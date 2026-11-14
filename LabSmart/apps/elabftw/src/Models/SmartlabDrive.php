<?php

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Elabftw\CreateUploadFromUploadedFile;
use Elabftw\Elabftw\Db;
use Elabftw\Enums\AccessType;
use Elabftw\Enums\Storage;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabSchemaMigrator;
use PDO;
use PDOStatement;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function hash_file;
use function mb_strlen;
use function pathinfo;

/**
 * "我的空间" personal drive (V3): per-user folders/files plus app login tokens.
 * Files are the student's own raw instrument data; they can later be linked to
 * an experiment as normal uploads. Nothing here is experiment-bound until linked.
 */
final class SmartlabDrive extends AbstractSmartlabModel
{
    public const string STORAGE_PREFIX = 'drive';

    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        self::ensureSchema();
    }

    public static function ensureSchema(): void
    {
        $migrator = new SmartlabSchemaMigrator();
        $migrator->ensureTable('smartlab_app_tokens', 'CREATE TABLE IF NOT EXISTS smartlab_app_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            team_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT "",
            token_hash CHAR(64) NOT NULL,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_smartlab_app_tokens_hash (token_hash),
            CONSTRAINT fk_smartlab_app_tokens_user FOREIGN KEY (user_id) REFERENCES users(userid) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
        $migrator->ensureTable('smartlab_user_folders', 'CREATE TABLE IF NOT EXISTS smartlab_user_folders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            path VARCHAR(700) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_smartlab_user_folders (user_id, path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
        $migrator->ensureTable('smartlab_user_files', 'CREATE TABLE IF NOT EXISTS smartlab_user_files (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            path VARCHAR(700) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            long_name VARCHAR(255) NOT NULL,
            storage TINYINT UNSIGNED NOT NULL DEFAULT 1,
            filesize BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sha256 CHAR(64) NOT NULL DEFAULT "",
            mime VARCHAR(255) NOT NULL DEFAULT "",
            source_device VARCHAR(255) NOT NULL DEFAULT "",
            source_path VARCHAR(700) NOT NULL DEFAULT "",
            source_modified_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_smartlab_user_files (user_id, path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
    }

    private function query(string $sql, array $params = array()): PDOStatement
    {
        $req = $this->Db->prepare($sql);
        foreach ($params as $key => $value) {
            $req->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $this->Db->execute($req);
        return $req;
    }

    /** Normalise a client-supplied path: forward slashes, no leading/trailing slash, no "..". */
    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $out = array();
        foreach (explode('/', $path) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $part;
        }
        return implode('/', $out);
    }

    private static function parentOf(string $path): string
    {
        $pos = strrpos($path, '/');
        return $pos === false ? '' : substr($path, 0, $pos);
    }

    /* ------------------------------------------------------------- tokens -- */

    public static function createToken(int $userId, int $team, string $name): string
    {
        self::ensureSchema();
        $token = bin2hex(random_bytes(24));
        $Db = Db::getConnection();
        $req = $Db->prepare('INSERT INTO smartlab_app_tokens (user_id, team_id, name, token_hash) VALUES (:u, :t, :n, :h)');
        $req->bindValue(':u', $userId, PDO::PARAM_INT);
        $req->bindValue(':t', $team, PDO::PARAM_INT);
        $req->bindValue(':n', trim($name) === '' ? 'SmartLab 桌面应用' : mb_substr(trim($name), 0, 255));
        $req->bindValue(':h', hash('sha256', $token));
        $Db->execute($req);
        return $token;
    }

    /** @return array{id:int,user_id:int,team_id:int,name:string}|null */
    public static function lookupToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
            return null;
        }
        self::ensureSchema();
        $Db = Db::getConnection();
        $req = $Db->prepare('SELECT id, user_id, team_id, name FROM smartlab_app_tokens WHERE token_hash = :h AND revoked_at IS NULL LIMIT 1');
        $req->bindValue(':h', hash('sha256', $token));
        $Db->execute($req);
        $row = $req->fetch();
        return $row === false ? null : $row;
    }

    public static function touchToken(int $id): void
    {
        $Db = Db::getConnection();
        $req = $Db->prepare('UPDATE smartlab_app_tokens SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id');
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $Db->execute($req);
    }

    public function revokeToken(string $token): void
    {
        $this->query('UPDATE smartlab_app_tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = :h AND user_id = :u',
            array('h' => hash('sha256', $token), 'u' => (int) $this->Users->userid));
    }

    /** @return array<int, array<string, mixed>> */
    public function listDevices(): array
    {
        return $this->query('SELECT id, name, last_seen_at, created_at FROM smartlab_app_tokens
            WHERE user_id = :u AND revoked_at IS NULL ORDER BY id DESC LIMIT 50',
            array('u' => (int) $this->Users->userid))->fetchAll();
    }

    public function revokeDevice(int $id): void
    {
        $this->query('UPDATE smartlab_app_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :u',
            array('id' => $id, 'u' => (int) $this->Users->userid));
    }

    /* ------------------------------------------------------------ folders -- */

    public function ensureFolderChain(string $dir): void
    {
        $dir = self::normalizePath($dir);
        if ($dir === '') {
            return;
        }
        $cumulative = '';
        foreach (explode('/', $dir) as $part) {
            $cumulative = $cumulative === '' ? $part : $cumulative . '/' . $part;
            $this->query('INSERT IGNORE INTO smartlab_user_folders (team_id, user_id, path) VALUES (:t, :u, :p)',
                array('t' => (int) $this->Users->team, 'u' => (int) $this->Users->userid, 'p' => $cumulative));
        }
    }

    public function mkdir(string $path): string
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            throw new AppException('文件夹名称不能为空。');
        }
        if (mb_strlen($path) > 700) {
            throw new AppException('路径过长。');
        }
        $this->ensureFolderChain($path);
        return $path;
    }

    /** @return array{path:string,breadcrumb:array,folders:array,files:array,usage:array} */
    public function listSpace(string $dir): array
    {
        $dir = self::normalizePath($dir);
        $userId = (int) $this->Users->userid;
        $like = $dir === '' ? '' : ' AND path LIKE :p';

        $folderRows = $this->query('SELECT path FROM smartlab_user_folders WHERE user_id = :u' . $like,
            $like === '' ? array('u' => $userId) : array('u' => $userId, 'p' => $dir . '/%'))->fetchAll();
        $fileRows = $this->query('SELECT * FROM smartlab_user_files WHERE user_id = :u' . $like . ' ORDER BY path',
            $like === '' ? array('u' => $userId) : array('u' => $userId, 'p' => $dir . '/%'))->fetchAll();

        $folders = array();
        foreach ($folderRows as $row) {
            if (self::parentOf((string) $row['path']) !== $dir) {
                continue;
            }
            $folders[] = array('name' => basename((string) $row['path']), 'path' => (string) $row['path']);
        }
        $files = array();
        foreach ($fileRows as $row) {
            if (self::parentOf((string) $row['path']) !== $dir) {
                continue;
            }
            $files[] = self::formatFile($row);
        }
        $breadcrumb = array(array('name' => '全部文件', 'path' => ''));
        if ($dir !== '') {
            $cumulative = '';
            foreach (explode('/', $dir) as $part) {
                $cumulative = $cumulative === '' ? $part : $cumulative . '/' . $part;
                $breadcrumb[] = array('name' => $part, 'path' => $cumulative);
            }
        }
        $usage = $this->query('SELECT COUNT(*) AS n, COALESCE(SUM(filesize), 0) AS bytes FROM smartlab_user_files WHERE user_id = :u',
            array('u' => $userId))->fetch();
        return array('path' => $dir, 'breadcrumb' => $breadcrumb, 'folders' => $folders, 'files' => $files, 'usage' => $usage);
    }

    /* -------------------------------------------------------------- files -- */

    /** Full path index used by the desktop sync client. @return array<string, mixed> */
    public function indexSpace(): array
    {
        $folders = $this->query('SELECT path FROM smartlab_user_folders WHERE user_id = :u ORDER BY path',
            array('u' => (int) $this->Users->userid))->fetchAll(PDO::FETCH_COLUMN);
        $files = $this->query('SELECT path, sha256, filesize FROM smartlab_user_files WHERE user_id = :u ORDER BY path',
            array('u' => (int) $this->Users->userid))->fetchAll();
        return array('folders' => array_values($folders), 'files' => $files);
    }

    /** @return array<string, mixed>|null */
    public function readFileByPath(string $path): ?array
    {
        $row = $this->query('SELECT * FROM smartlab_user_files WHERE user_id = :u AND path = :p LIMIT 1',
            array('u' => (int) $this->Users->userid, 'p' => self::normalizePath($path)))->fetch();
        return $row === false ? null : self::formatFile($row);
    }

    /** @return array<string, mixed>|null */
    public function readFile(int $id): ?array
    {
        $row = $this->query('SELECT * FROM smartlab_user_files WHERE id = :id AND user_id = :u LIMIT 1',
            array('id' => $id, 'u' => (int) $this->Users->userid))->fetch();
        return $row === false ? null : self::formatFile($row);
    }

    /**
     * Store (or replace) an uploaded file at a relative path.
     * Idempotent when the content hash is unchanged.
     *
     * @return array<string, mixed>
     */
    public function storeUploaded(string $relPath, string $tmpPath, string $sha256, int $size, ?int $sourceMtime, string $device, string $sourcePath): array
    {
        $path = self::normalizePath($relPath);
        if ($path === '' || basename($path) === '') {
            throw new AppException('文件名不能为空。');
        }
        if (mb_strlen($path) > 700) {
            throw new AppException('路径过长。');
        }
        if (!is_file($tmpPath)) {
            throw new AppException('上传文件不可读。');
        }
        $realHash = hash_file('sha256', $tmpPath);
        if (!hash_equals(strtolower($sha256), (string) $realHash)) {
            throw new AppException('文件校验失败（内容与声明不一致），已拒绝。');
        }
        $existing = $this->readFileByPath($path);
        if ($existing && $existing['sha256'] === $realHash) {
            return $existing;
        }
        $this->ensureFolderChain(self::parentOf($path));
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $longName = self::STORAGE_PREFIX . '/' . (int) $this->Users->userid . '/' . bin2hex(random_bytes(16))
            . ($ext !== '' ? '.' . preg_replace('/[^a-z0-9]/', '', $ext) : '');
        $fs = Storage::from(Storage::LOCAL->value)->getStorage()->getFs();
        $stream = fopen($tmpPath, 'rb');
        try {
            $fs->writeStream($longName, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $mime = function_exists('mime_content_type') ? (mime_content_type($tmpPath) ?: 'application/octet-stream') : 'application/octet-stream';
        $modified = $sourceMtime !== null && $sourceMtime > 0 ? gmdate('Y-m-d H:i:s', $sourceMtime) : null;
        if ($existing) {
            try {
                $fs->delete((string) $existing['long_name']);
            } catch (\Throwable) {
            }
            $this->query('UPDATE smartlab_user_files SET long_name = :l, filesize = :s, sha256 = :h, mime = :m,
                source_device = :d, source_path = :sp, source_modified_at = :sm, updated_at = UTC_TIMESTAMP() WHERE id = :id',
                array('l' => $longName, 's' => $size, 'h' => $realHash, 'm' => $mime, 'd' => $device,
                    'sp' => $sourcePath, 'sm' => $modified, 'id' => (int) $existing['id']));
            return (array) $this->readFileByPath($path);
        }
        $this->query('INSERT INTO smartlab_user_files (team_id, user_id, path, original_name, long_name, storage, filesize, sha256, mime, source_device, source_path, source_modified_at)
            VALUES (:t, :u, :p, :n, :l, 1, :s, :h, :m, :d, :sp, :sm)',
            array('t' => (int) $this->Users->team, 'u' => (int) $this->Users->userid, 'p' => $path, 'n' => basename($path),
                'l' => $longName, 's' => $size, 'h' => $realHash, 'm' => $mime, 'd' => $device, 'sp' => $sourcePath, 'sm' => $modified));
        return (array) $this->readFileByPath($path);
    }

    /** Delete a file, or a folder and everything inside it. Returns number of files removed. */
    public function deletePath(string $path): int
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            throw new AppException('请选择要删除的文件或文件夹。');
        }
        $files = $this->query('SELECT * FROM smartlab_user_files WHERE user_id = :u AND (path = :p OR path LIKE :sub)',
            array('u' => (int) $this->Users->userid, 'p' => $path, 'sub' => $path . '/%'))->fetchAll();
        $fs = Storage::from(Storage::LOCAL->value)->getStorage()->getFs();
        foreach ($files as $file) {
            try {
                $fs->delete((string) $file['long_name']);
            } catch (\Throwable) {
            }
        }
        $this->query('DELETE FROM smartlab_user_files WHERE user_id = :u AND (path = :p OR path LIKE :sub)',
            array('u' => (int) $this->Users->userid, 'p' => $path, 'sub' => $path . '/%'));
        $this->query('DELETE FROM smartlab_user_folders WHERE user_id = :u AND (path = :p OR path LIKE :sub)',
            array('u' => (int) $this->Users->userid, 'p' => $path, 'sub' => $path . '/%'));
        return count($files);
    }

    /** Copy a file (or every file inside a folder) into an experiment as normal uploads. */
    public function linkToExperiment(string $path, int $experimentId): int
    {
        $path = self::normalizePath($path);
        $files = $this->query('SELECT * FROM smartlab_user_files WHERE user_id = :u AND (path = :p OR path LIKE :sub)',
            array('u' => (int) $this->Users->userid, 'p' => $path, 'sub' => $path . '/%'))->fetchAll();
        if ($files === array()) {
            throw new AppException('没有可关联的文件。');
        }
        $Experiments = new Experiments($this->Users, $experimentId);
        $Experiments->readOne();
        $Experiments->canOrExplode(AccessType::Write);
        $Uploads = new Uploads($Experiments);
        $count = 0;
        foreach ($files as $file) {
            $tmp = tempnam(sys_get_temp_dir(), 'drive-');
            if ($tmp === false) {
                throw new AppException('暂时无法关联文件。');
            }
            try {
                $stream = Storage::from((int) $file['storage'])->getStorage()->getFs()->readStream((string) $file['long_name']);
                file_put_contents($tmp, stream_get_contents($stream));
                fclose($stream);
                $Uploads->create(new CreateUploadFromUploadedFile(new UploadedFile($tmp, (string) $file['original_name'], null, null, true), immutable: 1));
                ++$count;
            } finally {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }
        }
        return $count;
    }

    /** @param array<string, mixed> $row */
    private static function formatFile(array $row): array
    {
        $size = (int) $row['filesize'];
        $row['size_label'] = $size < 1024 ? $size . ' B' : ($size < 1048576 ? number_format($size / 1024, 1) . ' KB' : number_format($size / 1048576, 1) . ' MB');
        $row['modified_label'] = $row['source_modified_at'] ? (string) $row['source_modified_at'] : (string) $row['updated_at'];
        $row['url'] = 'smartlab-drive-file.php?id=' . (int) $row['id'];
        return $row;
    }
}
