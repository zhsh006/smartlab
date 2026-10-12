<?php

declare(strict_types=1);

namespace Elabftw\Services;

/**
 * Parse qPCR instrument CSV exports into structured well-level records.
 *
 * Supports:
 *  - Bio-Rad CFX96 / CFX Connect / CFX Opus CSV
 *  - ABI QuantStudio 3/5/7 CSV (and older StepOne / 7500 formats)
 *  - Generic "Well, Cq, Sample" CSV (best-effort)
 *
 * XLSX support requires phpoffice/phpspreadsheet; when absent, the caller
 * receives a clear error.
 */
final class SmartlabQpcrInstrumentParser
{
    /** @var "bio-rad"|"abi"|"generic"|null */
    private ?string $detectedFormat = null;

    /** @var array<int, string> detected header row */
    private array $headers = [];

    /** @var array<string, string> column mapping: internal key => detected header */
    private array $map = [];

    /** @var array<int, array> parsed wells */
    private array $wells = [];

    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Parse the file. Returns true on success.
     */
    public function parse(): bool
    {
        // This parser only supports CSV natively.
        return $this->parseCsv();
    }

    /**
     * @return "bio-rad"|"abi"|"generic"|null
     */
    public function detectedFormat(): ?string
    {
        return $this->detectedFormat;
    }

    /**
     * @return array<int, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return array<int, array{well_position:string, task_type:string, sample_label:string, group_label:string, target_gene:string, ct_value:?float, amp_status:?string, melt_peak_tm:?float, melt_peak_single:?int, note:string}>
     */
    public function wells(): array
    {
        return $this->wells;
    }

    /**
     * Convenience: parse and return wells in one call.
     * @return array{success:bool, format:?string, headers:array<int,string>, wells:array<int,array>, error:?string}
     */
    public static function parseFile(string $filePath): array
    {
        $parser = new self($filePath);
        if ($parser->parse()) {
            return array(
                'success' => true,
                'format' => $parser->detectedFormat(),
                'headers' => $parser->headers(),
                'wells' => $parser->wells(),
                'error' => null,
            );
        }
        return array(
            'success' => false,
            'format' => null,
            'headers' => array(),
            'wells' => array(),
            'error' => 'Unable to parse file as a supported qPCR instrument CSV.',
        );
    }

    // ─── internals ────────────────────────────────────────────────────

    private function parseCsv(): bool
    {
        $handle = @fopen($this->filePath, 'r');
        if ($handle === false) {
            return false;
        }

        // Skip leading blank / metadata rows, find the header row
        $headerRow = null;
        $headerLineNum = 0;
        $lineNum = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($row) < 2 || trim(implode('', $row)) === '') {
                continue;
            }
            $joined = strtolower(implode(' ', $row));
            // Detect header row by looking for "well" keyword
            if (str_contains($joined, 'well') && (str_contains($joined, 'cq') || str_contains($joined, 'ct') || str_contains($joined, 'sample') || str_contains($joined, 'target') || str_contains($joined, 'fluor'))) {
                $headerRow = $row;
                $headerLineNum = $lineNum;
                break;
            }
            // If more than 10 non-header rows seen without finding a header, give up detecting
            if ($lineNum > 20) {
                break;
            }
        }

        if ($headerRow === null) {
            fclose($handle);
            return false;
        }

        $this->headers = array_map('trim', $headerRow);
        $this->detectFormat($this->headers);

        // Scan for sample rows after the header
        $rows = array();
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2 || trim(implode('', $row)) === '') {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if ($rows === array()) {
            return false;
        }

        $this->wells = $this->mapRowsToWells($rows);
        return $this->wells !== array();
    }

    private function detectFormat(array $headers): void
    {
        $h = array_map('strtolower', $headers);
        $hStr = implode(' ', $h);

        // Bio-Rad: "Well", "Fluor", "Target", "Content"/"Sample", "Cq", "Melt Temperature"
        // Some CFX files use "Well", "Sample", "Target", "Cq Mean", "Melt Temp"
        if ((str_contains($hStr, 'fluor') || str_contains($hStr, 'cq mean') || str_contains($hStr, 'melt temp'))
            && str_contains($hStr, 'well')
            && (str_contains($hStr, 'target'))
        ) {
            $this->detectedFormat = 'bio-rad';

            $this->map = $this->buildHeaderMap($h, array(
                'well' => array('well'),
                'sample' => array('sample', 'content', 'sample name', 'sample_name'),
                'target' => array('target', 'target name', 'target_name', 'detector'),
                'cq' => array('cq', 'cq mean', 'ct', 'ct mean', 'cq_value', 'ct_value'),
                'melt_temp' => array('melt temperature', 'melt temp', 'melt_temperature', 'melt_peak_tm'),
            ));
            return;
        }

        // ABI: "Well", "Sample Name", "Target Name", "CT", "Ct Mean"
        // ABI also has "Well Position" in some exports
        if ((str_contains($hStr, 'sample name') || str_contains($hStr, 'target name'))
            && (str_contains($hStr, 'ct') || str_contains($hStr, 'cq'))
        ) {
            $this->detectedFormat = 'abi';

            $this->map = $this->buildHeaderMap($h, array(
                'well' => array('well', 'well position', 'well_position'),
                'sample' => array('sample name', 'sample_name', 'sample'),
                'target' => array('target name', 'target_name', 'target', 'detector'),
                'cq' => array('ct', 'ct mean', 'ct_value', 'cq', 'cq mean'),
                'melt_temp' => array('melt temperature', 'melt temp', 'tm'),
            ));
            return;
        }

        // Generic / best-effort fallback
        $this->detectedFormat = 'generic';
        $this->map = $this->buildHeaderMap($h, array(
            'well' => array('well', 'well position', 'well_position', 'position', 'pos'),
            'sample' => array('sample', 'sample name', 'sample_name', 'content', 'name'),
            'target' => array('target', 'target name', 'target_name', 'gene', 'detector', 'fluor'),
            'cq' => array('cq', 'ct', 'cq mean', 'ct mean', 'cq_value', 'ct_value', 'value'),
            'melt_temp' => array('melt temperature', 'melt temp', 'tm', 'melt_peak'),
        ));
    }

    /**
     * Build a column-name → index map from detected headers.
     *
     * @param array<int,string> $lowerHeaders
     * @param array<string, array<int,string>> $candidates — internal key → list of possible header names
     * @return array<string, string> — internal key → matched original header name
     */
    private function buildHeaderMap(array $lowerHeaders, array $candidates): array
    {
        $map = array();
        foreach ($candidates as $internalKey => $names) {
            foreach ($names as $name) {
                foreach ($lowerHeaders as $index => $header) {
                    if ($header === $name || str_contains($header, $name)) {
                        $map[$internalKey] = (string) $index;
                        break 2;
                    }
                }
            }
        }
        return $map;
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return array<int, array>
     */
    private function mapRowsToWells(array $rows): array
    {
        $wells = array();

        // Determine indices for each column
        $wellIdx = isset($this->map['well']) ? (int) $this->map['well'] : -1;
        $sampleIdx = isset($this->map['sample']) ? (int) $this->map['sample'] : -1;
        $targetIdx = isset($this->map['target']) ? (int) $this->map['target'] : -1;
        $cqIdx = isset($this->map['cq']) ? (int) $this->map['cq'] : -1;
        $meltIdx = isset($this->map['melt_temp']) ? (int) $this->map['melt_temp'] : -1;

        // If we don't have at least a well position, we cannot proceed
        if ($wellIdx < 0) {
            // Try to guess: first column that looks like "A01"
            $wellIdx = $this->guessWellColumnIndex($rows);
        }

        foreach ($rows as $row) {
            $wellPos = $wellIdx >= 0 ? trim((string) ($row[$wellIdx] ?? '')) : '';
            if ($wellPos === '') {
                continue;
            }

            // Normalize well position to "A01" format (uppercase row letter + zero-padded column)
            $wellPos = $this->normalizeWellPosition($wellPos);

            $sampleLabel = $sampleIdx >= 0 ? trim((string) ($row[$sampleIdx] ?? '')) : '';
            $targetGene = $targetIdx >= 0 ? trim((string) ($row[$targetIdx] ?? '')) : '';
            $ctRaw = $cqIdx >= 0 ? trim((string) ($row[$cqIdx] ?? '')) : '';
            $meltRaw = $meltIdx >= 0 ? trim((string) ($row[$meltIdx] ?? '')) : '';

            $ctValue = $this->parseNumericValue($ctRaw);
            $meltPeakTm = $this->parseNumericValue($meltRaw);

            // Determine amp_status based on Ct value
            $ampStatus = null;
            if ($ctValue === null && $ctRaw !== '') {
                // Non-numeric value — may be "Undetermined", "No Ct", etc.
                $ampStatus = $this->classifyAmpStatus($ctRaw);
            } elseif ($ctValue !== null && $ctValue > 0) {
                $ampStatus = 'ok';
            }

            $wells[] = array(
                'well_position' => $wellPos,
                'task_type' => 'unknown',
                'sample_label' => $sampleLabel,
                'group_label' => '',
                'target_gene' => $targetGene,
                'ct_value' => $ctValue,
                'amp_status' => $ampStatus,
                'melt_peak_tm' => $meltPeakTm,
                'melt_peak_single' => null,
                'replicate_group' => null,
                'is_outlier' => 0,
                'excluded' => 0,
                'note' => '',
            );
        }

        return $wells;
    }

    /**
     * Try to find the well column by looking at the first few rows for
     * a value matching "A01"-style pattern.
     *
     * @param array<int, array<int, string>> $rows
     */
    private function guessWellColumnIndex(array $rows): int
    {
        $sampleRows = array_slice($rows, 0, min(10, count($rows)));
        foreach ($sampleRows as $row) {
            foreach ($row as $index => $value) {
                $trimmed = trim((string) $value);
                if (preg_match('/^[A-Ha-h]\d{1,2}$/', $trimmed)) {
                    return $index;
                }
            }
        }
        return -1;
    }

    private function normalizeWellPosition(string $pos): string
    {
        $pos = strtoupper(trim($pos));
        if (preg_match('/^([A-H])(\d{1,2})$/', $pos, $m)) {
            return $m[1] . str_pad($m[2], 2, '0', STR_PAD_LEFT);
        }
        return $pos;
    }

    /**
     * @return float|null Returns null if value is non-numeric
     */
    private function parseNumericValue(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 6);
        }
        return null;
    }

    private function classifyAmpStatus(string $rawValue): string
    {
        $lower = strtolower($rawValue);
        if (str_contains($lower, 'undetermined') || str_contains($lower, 'undet') || str_contains($lower, 'no ct') || str_contains($lower, 'no cq')) {
            return 'none';
        }
        if (str_contains($lower, 'late') || $lower === '>35' || $lower === '>40') {
            return 'late';
        }
        return 'abnormal';
    }
}
