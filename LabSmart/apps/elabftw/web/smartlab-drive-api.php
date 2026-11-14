<?php

declare(strict_types=1);

/**
 * SmartLab Drive app API (V3, JSON). Used by the Windows desktop app and the web page.
 * Auth: POST/GET action=login (email+password) -> 48-hex token (X-SmartLab-Token header);
 * all other actions require a valid token.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elabftw\Auth\Local;
use Elabftw\Elabftw\Db;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\SmartlabDrive;
use Elabftw\Models\Users\Users;
use Elabftw\Enums\Storage;

header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $code, string $message): never
{
    out(array('ok' => false, 'error' => $message), $code);
}

function user_info(int $userId): array
{
    $req = Db::getConnection()->prepare('SELECT userid, email, firstname, lastname FROM users WHERE userid = :u');
    $req->bindValue(':u', $userId, PDO::PARAM_INT);
    Db::getConnection()->execute($req);
    $row = $req->fetch() ?: array('userid' => $userId, 'email' => '', 'firstname' => '', 'lastname' => '');
    $row['name'] = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: ($row['email'] ?? '');
    return $row;
}

$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? '')));
if ($action === '') {
    fail(400, '缺少 action 参数。');
}

try {
    if ($action === 'login') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $device = trim((string) ($_POST['device'] ?? 'Windows 应用'));
        if ($email === '' || $password === '') {
            fail(422, '请输入邮箱和密码。');
        }
        try {
            $response = (new Local($email, $password, true, false, false, 0, 6))->tryAuth();
        } catch (Throwable $e) {
            fail(401, '邮箱或密码不正确。');
        }
        $userId = $response->getAuthUserid();
        $team = $response->getSelectedTeam();
        if ($team <= 0) {
            $teams = $response->getSelectableTeams();
            $team = (int) ($teams[0]['id'] ?? 0);
        }
        if ($userId <= 0 || $team <= 0) {
            fail(401, '账号未加入任何实验室，无法登录。');
        }
        $token = SmartlabDrive::createToken($userId, $team, $device);
        out(array('ok' => true, 'token' => $token, 'user' => user_info($userId), 'team' => $team));
    }

    // Everything below requires a device token.
    $token = (string) ($_SERVER['HTTP_X_SMARTLAB_TOKEN'] ?? ($_POST['token'] ?? ($_GET['token'] ?? '')));
    $row = SmartlabDrive::lookupToken($token);
    if ($row === null) {
        fail(401, '登录已失效，请重新登录。');
    }
    SmartlabDrive::touchToken((int) $row['id']);
    $Users = new Users((int) $row['user_id'], (int) $row['team_id']);
    $drive = new SmartlabDrive($Users);

    if ($action === 'whoami') {
        out(array('ok' => true, 'user' => user_info((int) $row['user_id']), 'devices' => $drive->listDevices()));
    } elseif ($action === 'logout') {
        $drive->revokeToken($token);
        out(array('ok' => true));
    } elseif ($action === 'list') {
        out(array('ok' => true, 'space' => $drive->listSpace((string) ($_GET['path'] ?? $_POST['path'] ?? ''))));
    } elseif ($action === 'index') {
        out(array('ok' => true, 'index' => $drive->indexSpace()));
    } elseif ($action === 'mkdir') {
        $path = $drive->mkdir((string) ($_POST['path'] ?? ''));
        out(array('ok' => true, 'path' => $path));
    } elseif ($action === 'delete') {
        $n = $drive->deletePath((string) ($_POST['path'] ?? ''));
        out(array('ok' => true, 'deleted' => $n));
    } elseif ($action === 'link') {
        $n = $drive->linkToExperiment((string) ($_POST['path'] ?? ''), (int) ($_POST['experiment_id'] ?? 0));
        out(array('ok' => true, 'linked' => $n));
    } elseif ($action === 'download') {
        $file = $drive->readFile((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
        if ($file === null) {
            fail(404, '文件不存在。');
        }
        header('Content-Type: ' . ($file['mime'] !== '' ? $file['mime'] : 'application/octet-stream'));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string) $file['original_name']));
        header('Content-Length: ' . (int) $file['filesize']);
        $stream = Storage::from((int) $file['storage'])->getStorage()->getFs()->readStream((string) $file['long_name']);
        fpassthru($stream);
        fclose($stream);
        exit;
    } elseif ($action === 'upload') {
        $manifest = json_decode((string) ($_POST['manifest'] ?? '[]'), true);
        if (!is_array($manifest) || $manifest === array()) {
            fail(422, '缺少上传清单。');
        }
        $files = $_FILES['files'] ?? array();
        if (isset($files['name']) && !is_array($files['name'])) {
            foreach (array('name', 'tmp_name', 'size', 'error') as $key) {
                $files[$key] = array($files[$key]);
            }
        }
        if (count($manifest) !== count($files['tmp_name'] ?? array())) {
            fail(422, '上传清单与文件数量不一致。');
        }
        $stored = array();
        foreach ($manifest as $i => $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['sha256'])) {
                fail(422, '上传清单格式不正确。');
            }
            if ((int) ($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) {
                fail(422, '文件上传中断：' . (string) $entry['path']);
            }
            $tmp = (string) $files['tmp_name'][$i];
            $size = (int) ($files['size'][$i] ?? 0);
            if ($size !== (int) $entry['size']) {
                fail(422, '文件大小与声明不一致：' . (string) $entry['path']);
            }
            $file = $drive->storeUploaded(
                (string) $entry['path'],
                $tmp,
                (string) $entry['sha256'],
                $size,
                isset($entry['mtime']) ? (int) $entry['mtime'] : null,
                (string) $row['name'],
                (string) ($entry['source_path'] ?? ''),
            );
            $stored[] = array('path' => $file['path'], 'id' => (int) $file['id'], 'size' => (int) $file['filesize']);
        }
        out(array('ok' => true, 'uploaded' => $stored, 'count' => count($stored)));
    } else {
        fail(400, '不支持的 action：' . $action);
    }
} catch (AppException $e) {
    fail(422, $e->getMessage());
} catch (Throwable $e) {
    fail(500, '服务器处理失败：' . $e->getMessage());
}
