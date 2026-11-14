<?php

/**
 * Instrument data packages inbox (V3): simulated SmartLab Agent detection with
 * manual assignment — 宁可待关联，也不自动错配.
 *
 * @author SmartLab
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Elabftw\CreateUploadFromUploadedFile;
use Elabftw\Enums\AccessType;
use Elabftw\Enums\Storage;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabSchemaMigrator;
use Elabftw\Services\SmartlabAgentStorage;
use PDO;
use PDOStatement;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SmartlabDataCenter extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        (new SmartlabSchemaMigrator())->ensureTable('smartlab_data_packages', 'CREATE TABLE IF NOT EXISTS smartlab_data_packages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id INT UNSIGNED NOT NULL,
            source_label VARCHAR(255) NOT NULL DEFAULT "",
            package_label VARCHAR(255) NOT NULL DEFAULT "",
            files_json JSON NULL,
            detected_at DATETIME NULL,
            status VARCHAR(24) NOT NULL DEFAULT "pending",
            experiment_id INT UNSIGNED NULL,
            linked_by INT UNSIGNED NULL,
            linked_at DATETIME NULL,
            note TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_smartlab_data_packages_team_status (team_id, status),
            CONSTRAINT fk_smartlab_data_packages_team FOREIGN KEY (team_id) REFERENCES teams(id),
            CONSTRAINT fk_smartlab_data_packages_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE SET NULL,
            CONSTRAINT fk_smartlab_data_packages_linked_by FOREIGN KEY (linked_by) REFERENCES users(userid) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
        (new SmartlabSchemaMigrator())->ensureTable('smartlab_agents', 'CREATE TABLE IF NOT EXISTS smartlab_agents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT "",
            token VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT "active",
            last_seen_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_smartlab_agents_token (token),
            CONSTRAINT fk_smartlab_agents_team FOREIGN KEY (team_id) REFERENCES teams(id),
            CONSTRAINT fk_smartlab_agents_created_by FOREIGN KEY (created_by) REFERENCES users(userid) ON DELETE SET NULL
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

    public function readPackages(string $status = 'pending', int $limit = 20): array
    {
        $req = $this->query('SELECT p.*, e.title AS experiment_title,
            CONCAT(u.firstname, " ", u.lastname) AS linked_by_name
            FROM smartlab_data_packages p
            LEFT JOIN experiments e ON e.id = p.experiment_id
            LEFT JOIN users u ON u.userid = p.linked_by
            WHERE p.team_id = :team AND p.status = :status
              AND ((p.status = "pending" AND EXISTS (SELECT 1 FROM smartlab_data_package_readers pr
                    WHERE pr.package_id = p.id AND pr.user_id = :reader))
                OR (p.status = "linked" AND (
                    EXISTS (SELECT 1 FROM smartlab_project_entities pe JOIN smartlab_project_members pm ON pm.project_id = pe.project_id
                        WHERE pe.entity_type = "experiment" AND pe.entity_id = e.id AND pm.user_id = :member)
                    OR (e.userid = :owner AND NOT EXISTS (SELECT 1 FROM smartlab_project_entities pe
                        WHERE pe.entity_type = "experiment" AND pe.entity_id = e.id)))))
            ORDER BY p.detected_at DESC, p.id DESC
            LIMIT ' . (int) $limit, array('team' => (int) $this->Users->team, 'status' => $status,
                'reader' => (int) $this->Users->userid, 'member' => (int) $this->Users->userid, 'owner' => (int) $this->Users->userid));
        $rows = $req->fetchAll();
        foreach ($rows as &$row) {
            $row['files'] = json_decode((string) ($row['files_json'] ?? '[]'), true) ?: array();
            $row['detected_label'] = self::formatTime((string) ($row['detected_at'] ?? ''));
            $row['linked_label'] = self::formatTime((string) ($row['linked_at'] ?? ''));
            $row['integrity_warning'] = '';
            $paths = array();
            foreach ($row['files'] as &$file) {
                $stored = (string) ($file['stored'] ?? '');
                if ($stored === '' || isset($paths[$stored]) || !is_file('/elabftw/agent-inbox/' . $stored) || empty($file['sha256'])) {
                    $row['integrity_warning'] = '原件需要核对，暂不能归档。';
                }
                $paths[$stored] = true;
                $file['size_label'] = is_numeric($file['size'] ?? null)
                    ? ((int) $file['size'] < 1024 ? $file['size'] . ' B' : number_format((float) $file['size'] / 1024, 1) . ' KB')
                    : ($file['size'] ?? '未知');
                $sourceParts = explode('/', str_replace('\\', '/', (string) ($file['source_path'] ?? '')));
                $file['source_path_label'] = implode('/', array_slice($sourceParts, -2));
                $file['mtime_label'] = !empty($file['source_modified_at']) ? self::formatTime($file['source_modified_at']) : '未提供源文件时间';
            }
            unset($file);
        }
        unset($row);
        return $rows;
    }

    /** @return array<int, array<string, mixed>> experiments the current user can attach packages to */
    public function readExperimentOptions(): array
    {
        $rows = $this->query('SELECT id, title FROM experiments
            WHERE team = :team AND userid = :user AND state != 2
            ORDER BY modified_at DESC LIMIT 50',
            array('team' => (int) $this->Users->team, 'user' => (int) $this->Users->userid))->fetchAll();
        return array_values(array_filter($rows, fn(array $row): bool => $this->canReadExperiment((int) $row['id'])));
    }

    public function linkPackage(int $packageId, int $experimentId): void
    {
        $this->lastImportCount = 0;
        $lock = 'sl-link-' . $packageId;
        if ((int) $this->query('SELECT GET_LOCK(:name,10)', array('name' => $lock))->fetchColumn() !== 1) {
            throw new AppException('数据包正在处理，请稍后重试。');
        }
        try {
        $package = $this->query('SELECT p.* FROM smartlab_data_packages p
            WHERE p.id = :id AND p.team_id = :team AND EXISTS
                (SELECT 1 FROM smartlab_data_package_readers pr WHERE pr.package_id=p.id AND pr.user_id=:user) LIMIT 1',
            array('id' => $packageId, 'team' => (int) $this->Users->team, 'user' => (int) $this->Users->userid))->fetch();
        if (!$package) {
            throw new AppException('数据包不存在或不属于当前实验室。');
        }
        if (($package['status'] ?? '') !== 'pending') {
            throw new AppException('该数据包已处理，请刷新页面。');
        }
        if (!$this->canReadExperiment($experimentId)) { throw new AppException('没有该课题实验的访问权限。'); }
        if (!empty($package['experiment_id']) && (int) $package['experiment_id'] !== $experimentId) {
            throw new AppException('此包已部分导入，请先继续原实验的导入。');
        }
        $experiment = new Experiments($this->Users, $experimentId);
        $experiment->readOne();
        $experiment->canOrExplode(AccessType::Write);

        // Import the agent-saved files as regular experiment uploads (RAW 进入实验档案).
        $files = json_decode((string) ($package['files_json'] ?? '[]'), true) ?: array();
        if ($files === array()) { throw new AppException('数据包没有原件，不能归档。'); }
        $paths = array();
        foreach ($files as $i => $file) {
            try { $paths[$i] = SmartlabAgentStorage::verifiedPath($file, '/elabftw/agent-inbox'); }
            catch (\RuntimeException $e) { throw new AppException($e->getMessage()); }
        }
        $imported = 0;
        $Uploads = new Uploads($experiment);
        foreach ($files as $i => &$file) {
            $path = $paths[$i];
            try {
                // CreateUploadFromUploadedFile reads from PHP's upload tmp dir, so put a copy there.
                $uploadId = (int) ($file['upload_id'] ?? 0);
                if (!$uploadId) {
                    $tmpCopy = '/tmp/' . bin2hex(random_bytes(8));
                    if (!copy($path, $tmpCopy)) { throw new \RuntimeException('cannot copy original'); }
                    try {
                        $uploadId = (int) $Uploads->create(new CreateUploadFromUploadedFile(
                            new UploadedFile($tmpCopy, (string) $file['name'], null, null, true), immutable: 1));
                        $file['upload_id'] = $uploadId;
                    } finally {
                        if (is_file($tmpCopy)) { unlink($tmpCopy); }
                    }
                }
                $upload = (new Uploads($experiment, $uploadId))->readOne();
                $stream = Storage::from((int) $upload['storage'])->getStorage()->getFs()->readStream($upload['long_name']);
                $digest = hash_init('sha256');
                try { hash_update_stream($digest, $stream); }
                finally { fclose($stream); }
                if (!hash_equals($file['sha256'], hash_final($digest))) { throw new \RuntimeException('stored file verification failed'); }
                $this->query('UPDATE uploads SET hash=:hash, hash_algorithm="sha256", immutable=1 WHERE id=:id',
                    array('hash' => $file['sha256'], 'id' => $uploadId));
                $file['upload_status'] = 'imported';
                unset($file['upload_error']);
                $imported++;
            } catch (\Throwable $e) {
                $file['upload_status'] = 'failed';
                $file['upload_error'] = '导入未完成，原件仍保留，可重试。';
                $this->query('UPDATE smartlab_data_packages SET experiment_id=:experiment, files_json=:files WHERE id=:id',
                    array('experiment' => $experimentId, 'files' => json_encode($files), 'id' => $packageId));
                throw new AppException('导入未完成，数据包仍待处理，请重试。');
            }
            $this->query('UPDATE smartlab_data_packages SET experiment_id=:experiment, files_json=:files WHERE id=:id',
                array('experiment' => $experimentId, 'files' => json_encode($files), 'id' => $packageId));
        }
        unset($file);

        $this->query('UPDATE smartlab_data_packages
            SET status = "linked", experiment_id = :experiment, linked_by = :user, linked_at = UTC_TIMESTAMP(), files_json = :files
            WHERE id = :id AND status = "pending"', array(
            'experiment' => $experimentId,
            'user' => (int) $this->Users->userid,
            'files' => json_encode($files, JSON_UNESCAPED_UNICODE),
            'id' => $packageId,
        ));
        $this->lastImportCount = $imported;
        } finally {
            $this->query('SELECT RELEASE_LOCK(:name)', array('name' => $lock));
        }
    }

    public int $lastImportCount = 0;

    /** @return array<int, array<string, mixed>> */
    public function readAgents(): array
    {
        if (!$this->canManageAgents()) { return array(); }
        return $this->query('SELECT a.id,a.name,a.status,a.last_seen_at,a.created_at,
            CONCAT(u.firstname, " ", u.lastname) AS created_by_name,
            CONCAT(recipient.firstname, " ", recipient.lastname) AS recipient_name
            FROM smartlab_agents a
            LEFT JOIN users u ON u.userid = a.created_by
            LEFT JOIN smartlab_agent_recipients ar ON ar.agent_id=a.id
            LEFT JOIN users recipient ON recipient.userid=COALESCE(ar.user_id,a.created_by)
            WHERE a.team_id = :team
            ORDER BY a.id DESC', array('team' => (int) $this->Users->team))->fetchAll();
    }

    /** @return array{id: int, token: string} */
    public function createAgent(string $name, int $recipientId = 0): array
    {
        if (!$this->canManageAgents()) { throw new AppException('只有管理员可以配置设备接入。'); }
        if (!$this->query('SELECT 1 FROM users2teams WHERE users_id=:user AND teams_id=:team LIMIT 1',
            array('user' => $recipientId, 'team' => (int) $this->Users->team))->fetchColumn()) {
            throw new AppException('请选择当前团队的数据接收人。');
        }
        $name = trim($name);
        if ($name === '') {
            $name = '仪器电脑 Agent';
        }
        $token = bin2hex(random_bytes(24));
        $this->query('INSERT INTO smartlab_agents (team_id, name, token, created_by) VALUES (:team, :name, :token, :user)', array(
            'team' => (int) $this->Users->team,
            'name' => $name,
            'token' => hash('sha256', $token),
            'user' => (int) $this->Users->userid,
        ));
        $agentId = (int) $this->Db->lastInsertId();
        $this->query('INSERT INTO smartlab_agent_recipients (agent_id,user_id) VALUES (:agent,:user)',
            array('agent' => $agentId, 'user' => $recipientId));
        return array('id' => $agentId, 'token' => $token);
    }

    public function canManageAgents(): bool
    {
        return $this->Users->isAdmin || (int) ($this->Users->userData['is_sysadmin'] ?? 0) === 1;
    }

    private function canReadExperiment(int $experimentId): bool
    {
        return (bool) $this->query('SELECT 1 FROM experiments e WHERE e.id=:id AND e.team=:team AND (
            EXISTS (SELECT 1 FROM smartlab_project_entities pe JOIN smartlab_project_members pm ON pm.project_id=pe.project_id
                WHERE pe.entity_type="experiment" AND pe.entity_id=e.id AND pm.user_id=:member)
            OR (e.userid=:owner AND NOT EXISTS (SELECT 1 FROM smartlab_project_entities pe
                WHERE pe.entity_type="experiment" AND pe.entity_id=e.id))) LIMIT 1',
            array('id' => $experimentId, 'team' => (int) $this->Users->team,
                'member' => (int) $this->Users->userid, 'owner' => (int) $this->Users->userid))->fetchColumn();
    }

    private static function formatTime(string $utc): string
    {
        if ($utc === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('m-d H:i');
        } catch (\Exception) {
            return '';
        }
    }
}
