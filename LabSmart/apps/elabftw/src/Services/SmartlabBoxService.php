<?php

declare(strict_types=1);

namespace Elabftw\Services;

use DateTimeImmutable;
use Elabftw\Elabftw\BuildInfo;
use Elabftw\Elabftw\Db;
use PDO;
use Throwable;

final class SmartlabBoxService
{
    private PDO $Db;

    public function __construct()
    {
        $this->Db = Db::getConnection();
    }

    public function readConfig(): array
    {
        $path = $this->getConfigPath();
        if (!is_file($path)) {
            return array(
                'initialized' => false,
                'lab_name' => '',
                'admin_email' => '',
                'site_url' => $this->detectSiteUrl(),
                'default_template' => 'LabSmart MVP 默认实验模板',
            );
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return array('initialized' => false);
        }

        return array_merge(array('initialized' => true), $decoded);
    }

    public function saveConfig(array $payload, int $teamId, int $userId): array
    {
        $config = array(
            'initialized' => true,
            'lab_name' => trim((string) ($payload['lab_name'] ?? '')),
            'admin_email' => trim((string) ($payload['admin_email'] ?? '')),
            'site_url' => trim((string) ($payload['site_url'] ?? '')),
            'default_template' => trim((string) ($payload['default_template'] ?? '')),
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'updated_by' => $userId,
        );
        if (($this->readConfig()['initialized'] ?? false) === false) {
            $config['initialized_at'] = $config['updated_at'];
        }
        if ($config['lab_name'] === '') {
            $config['lab_name'] = 'LabSmart Team';
        }
        if ($config['site_url'] === '') {
            $config['site_url'] = $this->detectSiteUrl();
        }
        if ($config['default_template'] === '') {
            $config['default_template'] = 'LabSmart MVP 默认实验模板';
        }

        $this->ensureDirectory(dirname($this->getConfigPath()));
        file_put_contents($this->getConfigPath(), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($teamId > 0) {
            $req = $this->Db->prepare('UPDATE teams SET name = :name WHERE id = :team_id');
            $req->execute(array('name' => $config['lab_name'], 'team_id' => $teamId));
        }

        return $config;
    }

    public function readStatus(): array
    {
        $uploadsDir = $this->getUploadsDir();
        $backupDir = $this->getBackupDir();
        $dbStatus = $this->readDatabaseStatus();

        return array(
            'version' => getenv('SMARTLAB_BOX_VERSION') ?: '0.1.0-mvp',
            'elabftw_version' => BuildInfo::VERSION,
            'site_url' => $this->detectSiteUrl(),
            'config_path' => $this->getConfigPath(),
            'backup_dir' => $backupDir,
            'uploads_dir' => $uploadsDir,
            'update_dir' => $this->getUpdateDir(),
            'services' => array(
                array('name' => 'Web 服务', 'key' => 'web', 'ok' => true, 'detail' => 'PHP ' . PHP_VERSION),
                array('name' => '数据库', 'key' => 'mysql', 'ok' => $dbStatus['ok'], 'detail' => $dbStatus['detail']),
                array('name' => 'Redis/缓存', 'key' => 'redis', 'ok' => $this->checkTcp((string) (getenv('SMARTLAB_REDIS_HOST') ?: 'redis'), (int) (getenv('SMARTLAB_REDIS_PORT') ?: 6379)), 'detail' => (getenv('SMARTLAB_REDIS_HOST') ?: 'redis') . ':' . (getenv('SMARTLAB_REDIS_PORT') ?: '6379')),
                array('name' => '反向代理', 'key' => 'proxy', 'ok' => true, 'detail' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'direct')),
            ),
            'disk' => array(
                'uploads' => $this->readDisk($uploadsDir),
                'backups' => $this->readDisk($backupDir),
            ),
            'backups' => $this->listBackups(),
            'updates' => $this->listUpdates(),
        );
    }

    public function createBackup(int $userId): array
    {
        $backupDir = $this->getBackupDir();
        $uploadsDir = $this->getUploadsDir();
        $this->ensureDirectory($backupDir);
        $stamp = (new DateTimeImmutable())->format('Ymd-His');
        $dbFile = $backupDir . '/labsmart-box-db-' . $stamp . '.sql';
        $uploadsFile = $backupDir . '/labsmart-box-uploads-' . $stamp . '.tar.gz';
        $manifestFile = $backupDir . '/labsmart-box-' . $stamp . '.manifest.json';

        $this->dumpDatabase($dbFile);
        $this->archiveUploads($uploadsDir, $uploadsFile);

        $manifest = array(
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'created_by' => $userId,
            'database' => basename($dbFile),
            'uploads' => basename($uploadsFile),
            'restore_command' => './deploy/box/scripts/restore.sh ' . $stamp,
        );
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return array(
            'stamp' => $stamp,
            'database' => $dbFile,
            'uploads' => $uploadsFile,
            'manifest' => $manifestFile,
        );
    }

    public function storeUpdatePackage(?object $uploadedFile): ?string
    {
        if ($uploadedFile === null || !method_exists($uploadedFile, 'isValid') || !$uploadedFile->isValid()) {
            return null;
        }
        $this->ensureDirectory($this->getUpdateDir());
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $uploadedFile->getClientOriginalName());
        $target = $this->getUpdateDir() . '/' . (new DateTimeImmutable())->format('Ymd-His') . '-' . $name;
        $uploadedFile->move($this->getUpdateDir(), basename($target));
        return $target;
    }

    private function dumpDatabase(string $target): void
    {
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write database backup: ' . $target);
        }
        fwrite($handle, '-- LabSmart Box database backup' . PHP_EOL);
        fwrite($handle, '-- Created at ' . (new DateTimeImmutable())->format(DATE_ATOM) . PHP_EOL . PHP_EOL);

        $tables = $this->Db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $tableName = (string) $table;
            fwrite($handle, 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $tableName) . '`;' . PHP_EOL);
            $create = $this->Db->query('SHOW CREATE TABLE `' . str_replace('`', '``', $tableName) . '`')->fetch(PDO::FETCH_ASSOC);
            fwrite($handle, (string) ($create['Create Table'] ?? array_values($create ?: array())[1] ?? '') . ';' . PHP_EOL . PHP_EOL);

            $rows = $this->Db->query('SELECT * FROM `' . str_replace('`', '``', $tableName) . '`', PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns = array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
                $values = array_map(fn(mixed $value): string => $value === null ? 'NULL' : $this->Db->quote((string) $value), array_values($row));
                fwrite($handle, sprintf('INSERT INTO `%s` (%s) VALUES (%s);%s', str_replace('`', '``', $tableName), implode(', ', $columns), implode(', ', $values), PHP_EOL));
            }
            fwrite($handle, PHP_EOL);
        }
        fclose($handle);
    }

    private function archiveUploads(string $uploadsDir, string $target): void
    {
        if (!is_dir($uploadsDir)) {
            $this->ensureDirectory($uploadsDir);
        }
        $command = sprintf(
            'tar -C %s -czf %s %s 2>&1',
            escapeshellarg(dirname($uploadsDir)),
            escapeshellarg($target),
            escapeshellarg(basename($uploadsDir)),
        );
        $output = shell_exec($command);
        if (!is_file($target)) {
            throw new \RuntimeException('Cannot archive uploads: ' . (string) $output);
        }
    }

    private function readDatabaseStatus(): array
    {
        try {
            $version = (string) $this->Db->query('SELECT VERSION()')->fetchColumn();
            return array('ok' => true, 'detail' => $version);
        } catch (Throwable $e) {
            return array('ok' => false, 'detail' => $e->getMessage());
        }
    }

    private function checkTcp(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private function readDisk(string $path): array
    {
        $this->ensureDirectory($path);
        $total = (int) disk_total_space($path);
        $free = (int) disk_free_space($path);
        return array(
            'path' => $path,
            'total' => $total,
            'free' => $free,
            'used' => max(0, $total - $free),
            'free_human' => $this->formatBytes($free),
            'total_human' => $this->formatBytes($total),
            'used_percent' => $total > 0 ? round((($total - $free) / $total) * 100, 1) : 0,
            'writable' => is_writable($path),
        );
    }

    private function listBackups(): array
    {
        return $this->listFiles($this->getBackupDir(), '/labsmart-box-.*\\.(sql|gz|json)$/');
    }

    private function listUpdates(): array
    {
        return $this->listFiles($this->getUpdateDir(), '/.*/');
    }

    private function listFiles(string $dir, string $pattern): array
    {
        $this->ensureDirectory($dir);
        $files = array_filter(scandir($dir) ?: array(), static fn(string $file): bool => $file !== '.' && $file !== '..' && preg_match($pattern, $file) === 1);
        rsort($files);
        return array_map(function (string $file) use ($dir): array {
            $path = $dir . '/' . $file;
            return array(
                'name' => $file,
                'size' => is_file($path) ? $this->formatBytes((int) filesize($path)) : '-',
                'modified' => is_file($path) ? date('Y-m-d H:i:s', (int) filemtime($path)) : '-',
            );
        }, array_values($files));
    }

    private function formatBytes(int $bytes): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            ++$unit;
        }
        return round($size, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }

    private function detectSiteUrl(): string
    {
        $configured = getenv('SITE_URL') ?: getenv('LABSMART_BOX_SITE_URL');
        if ($configured) {
            return (string) $configured;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'labsmart.box');
    }

    private function getConfigPath(): string
    {
        return (string) (getenv('SMARTLAB_BOX_CONFIG') ?: '/elabftw/uploads/smartlab-box/box-config.json');
    }

    private function getBackupDir(): string
    {
        return (string) (getenv('SMARTLAB_BOX_BACKUP_DIR') ?: '/backups');
    }

    private function getUploadsDir(): string
    {
        return (string) (getenv('SMARTLAB_BOX_UPLOADS_DIR') ?: '/elabftw/uploads');
    }

    private function getUpdateDir(): string
    {
        return (string) (getenv('SMARTLAB_BOX_UPDATE_DIR') ?: '/updates');
    }
}
