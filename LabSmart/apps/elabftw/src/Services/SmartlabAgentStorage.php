<?php
declare(strict_types=1);
namespace Elabftw\Services;
use RuntimeException;

/** Original display names are never storage identifiers. */
final class SmartlabAgentStorage
{
    public static function receive(array $uploads, array $manifest, string $root): array
    {
        $count = count($uploads['name'] ?? array());
        if ($count === 0 || $count > 200 || count($manifest) !== $count) {
            throw new RuntimeException('文件清单不完整，请使用更新后的 Agent。');
        }
        foreach ($manifest as $i => $expected) {
            $name = basename((string) ($uploads['name'][$i] ?? ''));
            $tmp = (string) ($uploads['tmp_name'][$i] ?? '');
            if (($uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file($tmp) || !is_array($expected)
                || $name === '' || $name === '.' || $name === '..'
                || $name !== ($expected['name'] ?? '')
                || !preg_match('/^[a-f0-9]{64}$/', (string) ($expected['sha256'] ?? ''))
                || !is_int($expected['size'] ?? null) || $expected['size'] <= 0
                || !is_int($expected['mtime_ns'] ?? null) || $expected['mtime_ns'] < 0
                || !is_string($expected['source_path'] ?? null) || strlen($expected['source_path']) > 4096
                || $expected['size'] > 2147483648 || filesize($tmp) !== $expected['size']
                || !hash_equals($expected['sha256'], hash_file('sha256', $tmp))) {
                throw new RuntimeException('文件大小或 SHA-256 校验失败，数据未接收。');
            }
        }
        $key = bin2hex(random_bytes(16));
        $dir = $root . '/' . $key;
        if (!mkdir($dir, 0770, true)) { throw new RuntimeException('原件存储目录不可写。'); }
        $stored = array();
        try {
            foreach ($manifest as $i => $expected) {
                $object = sprintf('%04d-', $i) . bin2hex(random_bytes(16));
                if (!move_uploaded_file($uploads['tmp_name'][$i], $dir . '/' . $object)) {
                    throw new RuntimeException('原件保存失败，请重试。');
                }
                $stored[] = array('asset_id' => $object, 'name' => $expected['name'],
                    'source_path' => (string) ($expected['source_path'] ?? $expected['name']),
                    'size' => $expected['size'], 'sha256' => $expected['sha256'],
                    'stored' => $key . '/' . $object,
                    'source_modified_at' => isset($expected['mtime_ns']) ? gmdate('Y-m-d H:i:s', (int) floor($expected['mtime_ns'] / 1e9)) : null,
                    'received_at' => gmdate('Y-m-d H:i:s'), 'verified' => true);
            }
        } catch (\Throwable $e) {
            foreach ($stored as $file) { unlink($root . '/' . $file['stored']); }
            rmdir($dir);
            throw $e;
        }
        return $stored;
    }

    public static function verifiedPath(array $file, string $root): string
    {
        $base = realpath($root);
        $path = realpath($root . '/' . (string) ($file['stored'] ?? ''));
        $hash = (string) ($file['sha256'] ?? '');
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)
            || !is_file($path) || !preg_match('/^[a-f0-9]{64}$/', $hash)
            || filesize($path) !== (int) ($file['size'] ?? -1)
            || !hash_equals($hash, hash_file('sha256', $path))) {
            throw new RuntimeException('原件缺失或校验不一致，请重新采集；当前数据包仍待处理。');
        }
        return $path;
    }
}
