<?php

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Elabftw\Db;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabSchemaMigrator;
use PDO;
use PDOStatement;

/**
 * Scan-to-login challenges (V3). A client (web/agent) starts a challenge and shows
 * a QR; the phone scans it and confirms with its own account; the client polls and
 * receives an app token. Short-lived (2 min) and single-use.
 */
final class SmartlabQrLogin extends AbstractSmartlabModel
{
    public const int TTL_SECONDS = 120;
    private const array PURPOSES = array('web', 'agent', 'mobile');

    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        (new SmartlabSchemaMigrator())->ensureTable('smartlab_qr_logins', 'CREATE TABLE IF NOT EXISTS smartlab_qr_logins (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            challenge CHAR(40) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT "pending",
            purpose VARCHAR(16) NOT NULL DEFAULT "web",
            user_id INT UNSIGNED NULL,
            team_id INT UNSIGNED NULL,
            device_label VARCHAR(255) NOT NULL DEFAULT "",
            token CHAR(48) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            confirmed_at DATETIME NULL,
            consumed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_qr_challenge (challenge)
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

    /** @return array{challenge:string,expires_in:int,scan_text:string} */
    public function start(string $purpose, string $deviceLabel, string $baseUrl): array
    {
        $purpose = in_array($purpose, self::PURPOSES, true) ? $purpose : 'web';
        $challenge = bin2hex(random_bytes(20));
        $this->query('INSERT INTO smartlab_qr_logins (challenge, status, purpose, device_label, expires_at)
            VALUES (:c, "pending", :p, :d, UTC_TIMESTAMP() + INTERVAL ' . self::TTL_SECONDS . ' SECOND)',
            array('c' => $challenge, 'p' => $purpose, 'd' => mb_substr(trim($deviceLabel), 0, 255)));
        return array('challenge' => $challenge, 'expires_in' => self::TTL_SECONDS,
            'scan_text' => rtrim($baseUrl, '/') . '/smartlab-mobile.php?c=' . $challenge);
    }

    /** @return array<string, mixed> */
    public function poll(string $challenge): array
    {
        $row = $this->fetch($challenge);
        if ($row === null) {
            throw new AppException('登录二维码已失效，请重新获取。');
        }
        if ($row['status'] === 'pending' && strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            $this->query('UPDATE smartlab_qr_logins SET status = "expired" WHERE id = :id', array('id' => (int) $row['id']));
            $row['status'] = 'expired';
        }
        $out = array('status' => (string) $row['status']);
        if ($row['status'] === 'confirmed' && $row['consumed_at'] === null) {
            $this->query('UPDATE smartlab_qr_logins SET consumed_at = UTC_TIMESTAMP() WHERE id = :id', array('id' => (int) $row['id']));
            $out['token'] = (string) $row['token'];
            $out['user'] = $this->userInfo((int) $row['user_id']);
        }
        return $out;
    }

    public function confirm(string $challenge, int $userId, int $teamId, string $deviceLabel): void
    {
        $row = $this->fetch($challenge);
        if ($row === null) {
            throw new AppException('二维码已失效，请让对方刷新。');
        }
        if ($row['status'] !== 'pending' || strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            throw new AppException('二维码已使用或已过期。');
        }
        $label = trim($deviceLabel) !== '' ? $deviceLabel : ('扫码登录 · ' . ($row['purpose'] ?? 'web'));
        $token = SmartlabDrive::createToken($userId, $teamId, mb_substr($label, 0, 255));
        $this->query('UPDATE smartlab_qr_logins SET status = "confirmed", user_id = :u, team_id = :t, device_label = :d,
            token = :tok, confirmed_at = UTC_TIMESTAMP() WHERE id = :id AND status = "pending"',
            array('u' => $userId, 't' => $teamId, 'd' => mb_substr($label, 0, 255), 'tok' => $token, 'id' => (int) $row['id']));
    }

    public function cancel(string $challenge): void
    {
        $this->query('UPDATE smartlab_qr_logins SET status = "cancelled" WHERE challenge = :c AND status = "pending"', array('c' => $challenge));
    }

    private function fetch(string $challenge): ?array
    {
        if (preg_match('/^[a-f0-9]{40}$/', $challenge) !== 1) {
            return null;
        }
        $row = $this->query('SELECT * FROM smartlab_qr_logins WHERE challenge = :c LIMIT 1', array('c' => $challenge))->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    private function userInfo(int $userId): array
    {
        $req = Db::getConnection()->prepare('SELECT userid, email, firstname, lastname FROM users WHERE userid = :u');
        $req->bindValue(':u', $userId, PDO::PARAM_INT);
        Db::getConnection()->execute($req);
        $row = $req->fetch() ?: array('userid' => $userId, 'email' => '', 'firstname' => '', 'lastname' => '');
        $row['name'] = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: ($row['email'] ?? '');
        return $row;
    }
}
