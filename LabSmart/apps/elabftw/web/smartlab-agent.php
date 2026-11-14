<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use Elabftw\Elabftw\Db;
use Elabftw\Services\SmartlabAgentStorage;
header('Content-Type: application/json; charset=utf-8');
function agentFail(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { agentFail(405, 'POST only'); }
$token = trim((string) ($_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{48}$/', $token)) { agentFail(401, 'missing or invalid token'); }
$lock = '';
$locked = false;
$stored = array();
try {
    $Db = Db::getConnection();
    // Existing credentials remain usable; new credentials are stored as hashes.
    $lookup = $Db->prepare('SELECT a.*, r.user_id AS recipient_id FROM smartlab_agents a
        LEFT JOIN smartlab_agent_recipients r ON r.agent_id = a.id
        WHERE (a.token = :legacy OR a.token = :hash) AND a.status = "active" LIMIT 1');
    $lookup->bindValue(':legacy', $token);
    $lookup->bindValue(':hash', hash('sha256', $token));
    $Db->execute($lookup);
    $agent = $lookup->fetch();
    if (!$agent) { agentFail(401, 'unknown or revoked token'); }
    $recipient = (int) ($agent['recipient_id'] ?? $agent['created_by'] ?? 0);
    if ($recipient <= 0) { agentFail(422, '请管理员配置数据接收人。'); }
    $manifest = json_decode((string) ($_POST['manifest'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || !array_is_list($manifest)) { agentFail(422, 'invalid manifest'); }
    $requestId = (string) ($_POST['request_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $requestId)) { agentFail(422, '请使用更新后的 Agent，缺少采集清单标识。'); }
    $manifestHash = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
    $lock = substr('sl-ingest-' . hash('sha256', $agent['id'] . ':' . $requestId), 0, 64);
    $guard = $Db->prepare('SELECT GET_LOCK(:name, 10)');
    $guard->bindValue(':name', $lock);
    $Db->execute($guard);
    if ((int) $guard->fetchColumn() !== 1) { agentFail(409, '采集正在处理，请稍后重试。'); }
    $locked = true;
    $receipt = $Db->prepare('SELECT * FROM smartlab_agent_receipts WHERE agent_id = :agent AND request_id = :request');
    $receipt->bindValue(':agent', (int) $agent['id'], PDO::PARAM_INT);
    $receipt->bindValue(':request', $requestId);
    $Db->execute($receipt);
    $existing = $receipt->fetch();
    if ($existing && !hash_equals($existing['manifest_hash'], $manifestHash)) {
        throw new RuntimeException('采集标识与清单不一致，拒绝覆盖。');
    }
    if ($existing && $existing['package_id']) {
        $packageId = (int) $existing['package_id'];
        $check = $Db->prepare('SELECT files_json FROM smartlab_data_packages WHERE id=:id');
        $check->bindValue(':id', $packageId, PDO::PARAM_INT);
        $Db->execute($check);
        foreach (json_decode((string) $check->fetchColumn(), true, 512, JSON_THROW_ON_ERROR) as $file) {
            SmartlabAgentStorage::verifiedPath($file, '/elabftw/agent-inbox');
        }
    } else {
        $files = $_FILES['files'] ?? array();
        if (isset($files['name']) && !is_array($files['name'])) {
            foreach (array('name', 'tmp_name', 'size', 'error') as $key) { $files[$key] = array($files[$key]); }
        }
        $stored = SmartlabAgentStorage::receive($files, $manifest, '/elabftw/agent-inbox');
        $Db->q('START TRANSACTION');
        $insert = $Db->prepare('INSERT INTO smartlab_data_packages (team_id,source_label,package_label,files_json,detected_at,status)
            VALUES (:team,:source,:label,:files,UTC_TIMESTAMP(),"pending")');
        $insert->bindValue(':team', (int) $agent['team_id'], PDO::PARAM_INT);
        // Device identity comes from its credential, never a submitted label.
        $insert->bindValue(':source', $agent['name']);
        $insert->bindValue(':label', mb_substr(trim((string) ($_POST['package_label'] ?? '仪器数据包')), 0, 255));
        $insert->bindValue(':files', json_encode($stored, JSON_THROW_ON_ERROR));
        $Db->execute($insert);
        $packageId = (int) $Db->lastInsertId();
        $reader = $Db->prepare('INSERT INTO smartlab_data_package_readers (package_id,user_id) VALUES (:package,:user)');
        $reader->bindValue(':package', $packageId, PDO::PARAM_INT);
        $reader->bindValue(':user', $recipient, PDO::PARAM_INT);
        $Db->execute($reader);
        $ack = $Db->prepare('INSERT INTO smartlab_agent_receipts (agent_id,request_id,manifest_hash,package_id) VALUES (:agent,:request,:hash,:package)');
        $ack->bindValue(':agent', (int) $agent['id'], PDO::PARAM_INT);
        $ack->bindValue(':request', $requestId);
        $ack->bindValue(':hash', $manifestHash);
        $ack->bindValue(':package', $packageId, PDO::PARAM_INT);
        $Db->execute($ack);
        $Db->q('COMMIT');
        $stored = array(); // Published files are never discarded on a later error.
    }
    $touch = $Db->prepare('UPDATE smartlab_agents SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id');
    $touch->bindValue(':id', (int) $agent['id'], PDO::PARAM_INT);
    $Db->execute($touch);
    echo json_encode(array('ok' => true, 'package_id' => $packageId, 'files' => count($manifest),
        'request_id' => $requestId, 'message' => '原件已校验，留在待关联数据中。'), JSON_UNESCAPED_UNICODE);
} catch (JsonException | RuntimeException $e) {
    if (isset($Db)) { try { $Db->q('ROLLBACK'); } catch (Throwable) {} }
    foreach ($stored as $file) { unlink('/elabftw/agent-inbox/' . $file['stored']); }
    http_response_code(422);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($Db)) { try { $Db->q('ROLLBACK'); } catch (Throwable) {} }
    foreach ($stored as $file) { unlink('/elabftw/agent-inbox/' . $file['stored']); }
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => '采集未完成，请重试或检查数据库迁移与存储。'), JSON_UNESCAPED_UNICODE);
} finally {
    if ($locked && isset($Db)) {
        $release = $Db->prepare('SELECT RELEASE_LOCK(:name)');
        $release->bindValue(':name', $lock);
        $Db->execute($release);
    }
}
