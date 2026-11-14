<?php
declare(strict_types=1);
namespace Elabftw\Services;
use Elabftw\Exceptions\AppException;

/** Suggested lysate volumes from an explicit protein mass and original-sample concentrations. */
final class SmartlabWbLoadingCalculator
{
    public static function calculate(string $loading, string $table): array
    {
        $mass = preg_match('/^(\d+(?:\.\d+)?)\s*(?:[uμµ]g|微克)(?:\b|\s|\/|$)/iu', trim($loading), $match) ? (float) $match[1] : null;
        if ($mass !== null && (!is_finite($mass) || $mass <= 0)) { $mass = null; }
        $lines = preg_split('/\r\n|\r|\n/', trim($table));
        if (count($lines) > 101) { throw new AppException('上样计算最多 100 个样本；更大的表可作为原始附件保存。'); }
        $result = array(); $headerUnit = '';
        foreach ($lines as $index => $line) {
            if (trim($line) === '') { continue; }
            $cells = str_getcsv($line, str_contains($line, "\t") ? "\t" : ',', '"', '');
            if ($index === 0 && preg_match('/sample|样本/i', $cells[0] ?? '') && preg_match('/concentration|浓度/i', $cells[1] ?? '')) {
                if (preg_match('/([uμµ]g\/[uμµ]L|mg\/mL|[uμµ]g\/mL)/iu', $cells[1], $match)) { $headerUnit = $match[1]; }
                continue;
            }
            $sample = trim((string) ($cells[0] ?? '')); $text = trim((string) ($cells[1] ?? '')); $unit = trim((string) ($cells[2] ?? $headerUnit));
            $key = strtolower(str_replace(array('μ', 'µ', ' '), array('u', 'u', ''), $unit));
            $factor = array('ug/ul' => 1.0, 'mg/ml' => 1.0, 'ug/ml' => 0.001)[$key] ?? null;
            $concentration = preg_match('/^\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/D', $text) ? (float) $text : null;
            $volume = $mass !== null && $factor !== null && $concentration !== null && is_finite($concentration) && $concentration > 0 ? $mass / ($concentration * $factor) : null;
            if ($volume !== null && !is_finite($volume)) { $volume = null; }
            $result[] = array('sample' => mb_substr($sample,0,200), 'concentration' => mb_substr($text,0,100), 'unit' => mb_substr($unit,0,100), 'volume_ul' => $volume !== null ? round($volume,3) : null);
        }
        return $result;
    }
}
