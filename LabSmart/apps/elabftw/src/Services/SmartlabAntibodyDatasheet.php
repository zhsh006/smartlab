<?php
declare(strict_types=1);
namespace Elabftw\Services;

use Elabftw\Exceptions\AppException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Local, conservative extraction. No LLM call and no guessed experimental values. */
final class SmartlabAntibodyDatasheet
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public static function read(?UploadedFile $file): array
    {
        if (!$file || !$file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw new AppException('请选择有效的 PDF 或 UTF-8 文本说明书，最大 5 MB。');
        }
        $bytes = file_get_contents($file->getPathname());
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = 'text/plain';
        if ($extension === 'pdf' && str_starts_with($bytes, '%PDF-')) {
            $mime = 'application/pdf';
            $output = tempnam(sys_get_temp_dir(), 'wb-sheet-');
            $diagnostics = tempnam(sys_get_temp_dir(), 'wb-sheet-log-');
            try {
                $process = proc_open(array('/usr/bin/timeout', '12', '/usr/bin/pdftotext', '-f', '1', '-l', '25', '-layout', $file->getPathname(), $output), array(0 => array('pipe', 'r'), 1 => array('file', $diagnostics, 'w'), 2 => array('redirect', 1)), $pipes);
                if (!is_resource($process)) { throw new AppException('说明书文本提取暂不可用，请粘贴说明书文字。'); }
                fclose($pipes[0]);
                $exit = proc_close($process);
                if ($exit !== 0) { throw new AppException('未能读取 PDF，请检查文件是否加密或改用文本说明书。'); }
                $text = file_get_contents($output);
            } finally {
                if (is_file($output)) { unlink($output); }
                if (is_file($diagnostics)) { unlink($diagnostics); }
            }
        } elseif ($extension === 'txt' && !str_contains($bytes, "\0") && mb_check_encoding($bytes, 'UTF-8')) {
            $text = $bytes;
        } else {
            throw new AppException('支持带文字的 PDF 和 UTF-8 .txt；扫描件可保留 PDF，并粘贴文字核对。');
        }
        if (strlen($text) > 300000) { throw new AppException('说明书文字过长，请使用该抗体的单独说明书。'); }
        return array('name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255), 'mime' => $mime,
            'hash' => hash('sha256', $bytes), 'bytes' => $bytes, 'text' => $text, 'fields' => self::extract($text));
    }

    public static function extract(string $text): array
    {
        if (strlen($text) > 300000 || !mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) { throw new AppException('说明书文字需为 UTF-8，且不超过 300 KB。'); }
        $text = str_replace(array("\r\n", "\r", "\f", "\xEF\xBB\xBF"), "\n", $text);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn($line) => $line !== ''));
        $fields = array();
        $labels = array(
            'host_species' => '宿主(?:种属)?|Host(?:\s+Species)?|Source\/Isotype',
            'reactivity' => '反应种属|适用种属|Species\s+Reactivity|Cross[- ]?Reactivity',
            'target' => '靶标|靶蛋白|Target(?:\s+Name)?',
            'molecular_weight' => '分子量(?:\s*\(kDa\))?|Molecular\s+Weight(?:\s*\(kDa\))?|MW\s*\(kDa\)',
            'clone' => '克隆号|Clone(?:\s+(?:Number|No\.?))?',
            'storage' => '保存条件|Storage(?:\s+Conditions)?',
            'wb_diluent' => 'WB\s*稀释液|WB\s*Diluent',
            'wb_incubation' => 'WB\s*孵育条件|WB\s*Incubation',
            'vendor' => '厂家|Manufacturer|Vendor',
            'catalog_no' => '货号|Catalog(?:ue)?\s*(?:Number|No\.?|#)',
        );
        foreach ($labels as $field => $label) {
            foreach ($lines as $index => $line) {
                if (preg_match('/^(?:' . $label . ')(?:\s*[:：]\s*|\s{2,})(.*)$/iu', $line, $match) || preg_match('/^(?:' . $label . ')$/iu', $line)) {
                    $match ??= array();
                    $value = trim($match[1] ?? '');
                    if ($value === '' && isset($lines[$index + 1]) && !preg_match('/[:：]/u', $lines[$index + 1])) { $value = $lines[$index + 1]; }
                    if ($field === 'molecular_weight' && preg_match('/kDa/i', $line) && !preg_match('/kDa/i', $value) && $value !== '') { $value .= ' kDa'; }
                    if ($value !== '') { $fields[$field] = mb_substr($value, 0, 500); }
                    break;
                }
            }
        }
        // Only a WB-labelled application row may supply a dilution. Never reuse IF/IHC.
        foreach ($lines as $index => $line) {
            if (!preg_match('/^(?:WB(?:\s*稀释(?:比例|度)?)?|Western\s+Blot(?:ting)?)(?:\b|\s|[:：])/iu', $line)) { continue; }
            $candidate = $line;
            if (!preg_match('/1\s*[:：]\s*\d/u', $candidate) && isset($lines[$index + 1]) && preg_match('/^1\s*[:：]\s*\d/u', $lines[$index + 1])) { $candidate .= ' ' . $lines[$index + 1]; }
            if (preg_match('/1\s*[:：]\s*\d[\d,]*(?:\s*[-–—~至]\s*(?:1\s*[:：]\s*)?\d[\d,]*)?/u', $candidate, $match)) { $fields['wb_dilution'] = trim($match[0]); break; }
        }
        // Some manufacturers put WB incubation/diluent in an IMPORTANT paragraph.
        foreach ($lines as $index => $line) {
            if (!preg_match('/(?:for\s+western\s+blots?|western\s+blotting).*incubat/iu', $line)) { continue; }
            $paragraph = implode(' ', array_slice($lines, $index, 4));
            if (preg_match('/antibody\s+in\s+(.+?)\s+at\s+\d/iu', $paragraph, $match)) { $fields['wb_diluent'] ??= mb_substr(trim($match[1]), 0, 500); }
            if (preg_match('/\b(\d+\s*°?\s*C).*?\b(overnight|\d+\s*(?:hours?|hrs?|minutes?|mins?))\b/iu', $paragraph, $match)) { $fields['wb_incubation'] ??= trim($match[1]) . '，' . trim($match[2]); }
            break;
        }
        return $fields;
    }

    public static function pasted(string $text): array
    {
        $fields = self::extract($text);
        return array('name' => '粘贴的说明书.txt', 'mime' => 'text/plain', 'hash' => hash('sha256', $text), 'bytes' => $text, 'fields' => $fields);
    }
}
