<?php
declare(strict_types=1);

namespace Elabftw\Services;

use Elabftw\Exceptions\AppException;
use ZipArchive;

/** Read source cells without calculating, grouping, or guessing sample identities. */
final class SmartlabQpcrImport
{
    public static function preview(string $bytes, string $name, string $sheet = ''): array
    {
        $sourceHash = hash('sha256', $bytes);
        if (strlen($bytes) > 10000000) { throw new AppException('此文件较大，原件已保留；请导出精简的结果表后再导入。'); }
        $sheets = array();
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx') {
            [$cells, $sheets, $sheet] = self::xlsx($bytes, $sheet);
        } else {
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), array('csv', 'tsv', 'txt'), true)) { throw new AppException('结果预览支持 CSV、TSV 和 XLSX；其他格式的原件仍可保存。'); }
            if (str_starts_with($bytes, "\xFF\xFE")) { $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE'); }
            if (str_starts_with($bytes, "\xFE\xFF")) { $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE'); }
            $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;
            if (!mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) { throw new AppException('请将结果表导出为 UTF-8 CSV 或 XLSX。'); }
            $delimiter = ','; $best = 0;
            foreach (array(',', "\t", ';') as $candidate) {
                foreach (array_slice(preg_split('/\r\n|\n|\r/', $bytes) ?: array(), 0, 100) as $line) {
                    $parts = str_getcsv($line, $candidate, '"', '');
                    $score = count($parts) + (array_intersect(array_map(self::normal(...), $parts), array('ct', 'cq', 'cp', 'crt', 'ctvalue')) ? 1000 : 0);
                    if ($score > $best) { $delimiter = $candidate; $best = $score; }
                }
            }
            $stream = fopen('php://temp', 'w+'); fwrite($stream, $bytes); rewind($stream);
            $cells = array();
            while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                if (count($row) > 200 || count($cells) > 5100) { fclose($stream); throw new AppException('单次预览最多 5000 行、200 列；完整原件已保留。'); }
                $cells[] = array_map(static fn($value): string => trim((string) $value), $row);
            }
            fclose($stream);
        }
        $headerIndex = null;
        foreach (array_slice($cells, 0, 100) as $index => $row) {
            if (array_intersect(array_map(self::normal(...), $row), array('ct', 'cq', 'cp', 'crt', 'ctvalue', 'ct值', 'cq值'))) { $headerIndex = $index; break; }
        }
        if ($headerIndex === null) { foreach ($cells as $index => $row) { if (count($row) > 1 && implode('', $row) !== '') { $headerIndex = $index; break; } } }
        if ($headerIndex === null) {
            if (count($sheets) > 1) {
                return array('headers' => array(), 'rows' => array(), 'suggested' => array_fill_keys(array('sample', 'target', 'well', 'ct', 'plate', 'run'), -1), 'sheets' => $sheets, 'sheet' => $sheet, 'hash' => $sourceHash, 'message' => '此工作表没有结果表，请切换到保存 Ct / Cq 的工作表。');
            }
            throw new AppException('未找到结果表，请检查文件内容。');
        }
        $headers = array();
        foreach ($cells[$headerIndex] as $i => $header) { $headers[] = trim((string) $header) ?: ('第 ' . ($i + 1) . ' 列'); }
        $rows = array_values(array_filter(array_slice($cells, $headerIndex + 1), static fn($row): bool => implode('', $row) !== ''));
        if (count($rows) > 5000) { throw new AppException('单次导入最多 5000 行；原件已保留。'); }
        foreach ($rows as &$row) {
            $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
            foreach ($row as $value) { if (mb_strlen((string) $value) > 4000) { throw new AppException('结果表包含过长单元格，请核对文件。'); } }
        }
        unset($row);
        $aliases = array('sample' => array('sample', 'samplename', 'sampleid', '样本', '样本名称'), 'target' => array('target', 'targetname', 'detector', 'detectorname', 'gene', '靶标', '基因'), 'well' => array('well', 'wellposition', '孔位'), 'ct' => array('ct', 'cq', 'cp', 'crt', 'ctvalue', 'ct值', 'cq值'), 'plate' => array('plate', 'plateid', '板', '板号'), 'run' => array('run', 'runid', '运行', '运行编号'));
        $suggested = array();
        foreach ($aliases as $key => $names) {
            $matches = array_keys(array_filter($headers, static fn($header): bool => in_array(self::normal($header), $names, true)));
            $suggested[$key] = count($matches) === 1 ? $matches[0] : -1;
        }
        return array('headers' => $headers, 'rows' => $rows, 'suggested' => $suggested, 'sheets' => $sheets, 'sheet' => $sheet, 'hash' => $sourceHash);
    }

    public static function project(array $preview, array $mapping, string $plate = '', string $run = ''): array
    {
        if (!isset($mapping['ct']) || !ctype_digit((string) $mapping['ct']) || !array_key_exists((int) $mapping['ct'], $preview['headers'])) { throw new AppException('请指定文件中的 Ct / Cq 列。'); }
        $clean = array();
        foreach (array('sample', 'target', 'well', 'ct', 'plate', 'run') as $key) {
            $value = $mapping[$key] ?? -1;
            if (!is_scalar($value) || !preg_match('/^-?\d+$/', (string) $value) || (int) $value < -1 || ((int) $value >= 0 && !array_key_exists((int) $value, $preview['headers']))) { throw new AppException('列对应已失效，请重新预览。'); }
            $clean[$key] = (int) $value;
        }
        $rows = array(); $seenWells = array();
        foreach ($preview['rows'] as $i => $source) {
            $row = array('source_row' => $i + 1);
            foreach ($clean as $key => $column) { $row[$key] = $column < 0 ? '' : (string) ($source[$column] ?? ''); }
            if ($row['plate'] === '') { $row['plate'] = $plate; }
            if ($row['run'] === '') { $row['run'] = $run; }
            $normalized = strtolower(trim($row['ct']));
            $row['ct_state'] = $normalized === '' || in_array($normalized, array('undetermined', 'no ct', 'no cq', 'n/a', 'na', 'null'), true) ? 'unknown' : (is_numeric($row['ct']) && is_finite((float) $row['ct']) && (float) $row['ct'] > 0 && (float) $row['ct'] <= 100 ? 'numeric' : 'needs_review');
            if ($row['well'] !== '') {
                if (!preg_match('/^([A-P])0?([1-9]|1\d|2[0-4])$/iD', trim($row['well']), $position)) { throw new AppException('第 ' . ($i + 1) . ' 行孔位“' . $row['well'] . '”无法识别，请核对原表；原件仍已保留。'); }
                $identity = self::canonicalWell($row, strtoupper($position[1]) . (int) $position[2]);
                if (isset($seenWells[$identity])) { throw new AppException('第 ' . ($i + 1) . ' 行与第 ' . $seenWells[$identity] . ' 行的板、运行、孔位及靶标重复，请核对原表。'); }
                $seenWells[$identity] = $i + 1;
            }
            $rows[] = $row;
        }
        return array('mapping' => $clean, 'rows' => $rows);
    }

    // Blank lines make str_getcsv() return [null]; normalise defensively.
    private static function normal(mixed $value): string { return mb_strtolower(preg_replace('/[\s_\-\.()]+/u', '', trim((string) $value)) ?? ''); }

    private static function canonicalWell(array $row, string $well): string
    {
        return json_encode(array($row['plate'], $row['run'], $well, $row['target']), JSON_THROW_ON_ERROR);
    }

    private static function xml(string $bytes): \SimpleXMLElement
    {
        if (stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<!ENTITY') !== false) { throw new AppException('结果文件包含不支持的 XML 声明。'); }
        $before = libxml_use_internal_errors(true);
        try { $xml = simplexml_load_string($bytes, \SimpleXMLElement::class, LIBXML_NONET); if ($xml === false) { throw new AppException('工作表格式不完整，请重新导出。'); } return $xml; }
        finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
    }

    private static function xlsx(string $bytes, string $sheet): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qpcr-');
        if ($path === false) { throw new AppException('暂时无法读取工作表。'); }
        file_put_contents($path, $bytes); $zip = new ZipArchive(); $opened = false;
        try {
            if ($zip->open($path) !== true) { throw new AppException('无法打开 XLSX，请检查文件。'); }
            $opened = true;
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) { $expanded += (int) ($zip->statIndex($i)['size'] ?? 0); }
            if ($zip->numFiles > 5000 || $expanded > 30000000) { throw new AppException('工作簿过大，请导出本次结果页。'); }
            $get = static function (string $name) use ($zip): string { $value = $zip->getFromName($name); if ($value === false) { throw new AppException('工作簿缺少结果页。'); } return $value; };
            $namespaces = array('s' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $wb = self::xml($get('xl/workbook.xml')); foreach ($namespaces as $key => $uri) { $wb->registerXPathNamespace($key, $uri); }
            $rels = self::xml($get('xl/_rels/workbook.xml.rels')); $targets = array();
            foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) { $attributes = $rel->attributes(); $targets[(string) $attributes['Id']] = (string) $attributes['Target']; }
            $sheets = array(); $target = '';
            foreach ($wb->xpath('//s:sheets/s:sheet') ?: array() as $entry) {
                $name = (string) $entry['name']; $sheets[] = $name;
                if (($sheet === '' && $target === '') || $sheet === $name) { $sheet = $name; $target = $targets[(string) $entry->attributes($namespaces['r'])['id']] ?? ''; }
            }
            if ($target === '' || str_contains($target, '..') || str_contains($target, '\\')) { throw new AppException('请选择有效工作表。'); }
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            $strings = array(); $shared = $zip->getFromName('xl/sharedStrings.xml');
            if ($shared !== false) {
                $xml = self::xml($shared); $xml->registerXPathNamespace('s', $namespaces['s']);
                foreach ($xml->xpath('//s:si') ?: array() as $entry) { $entry->registerXPathNamespace('s', $namespaces['s']); $strings[] = implode('', array_map(strval(...), $entry->xpath('.//s:t') ?: array())); }
            }
            $xml = self::xml($get($target)); $xml->registerXPathNamespace('s', $namespaces['s']); $rows = array();
            foreach ($xml->xpath('//s:sheetData/s:row') ?: array() as $row) {
                if (count($rows) > 5100) { throw new AppException('工作表最多读取 5000 行结果。'); }
                $row->registerXPathNamespace('s', $namespaces['s']); $values = array();
                foreach ($row->xpath('./s:c') ?: array() as $cell) {
                    preg_match('/^([A-Z]+)\d+$/', (string) $cell['r'], $match); $column = 0;
                    foreach (str_split($match[1] ?? 'A') as $letter) { $column = $column * 26 + ord($letter) - 64; }
                    if ($column > 200) { throw new AppException('工作表超过 200 列，请导出精简结果表。'); }
                    $cell->registerXPathNamespace('s', $namespaces['s']); $value = (string) (($cell->xpath('./s:v') ?: array())[0] ?? '');
                    if ((string) $cell['t'] === 's') { $value = $strings[(int) $value] ?? ''; }
                    elseif ((string) $cell['t'] === 'inlineStr') { $value = implode('', array_map(strval(...), $cell->xpath('./s:is//s:t') ?: array())); }
                    $values[$column - 1] = trim($value);
                }
                if ($values) { $line = array_fill(0, max(array_keys($values)) + 1, ''); foreach ($values as $i => $value) { $line[$i] = $value; } $rows[] = $line; }
            }
            return array($rows, $sheets, $sheet);
        } finally { if ($opened) { $zip->close(); } unlink($path); }
    }
}
