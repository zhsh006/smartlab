<?php

declare(strict_types=1);

/**
 * SmartLab scan-to-login API (JSON).
 *   start   (client)  -> create challenge, returns scan_text (encode as QR)
 *   poll    (client)  -> pending|confirmed(+token)|expired|consumed|cancelled
 *   confirm (phone)   -> requires X-SmartLab-Token of the SmartLab account
 *   cancel  (client)
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elabftw\Exceptions\AppException;
use Elabftw\Models\SmartlabDrive;
use Elabftw\Models\SmartlabQrLogin;
use Elabftw\Models\Users\Users;

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

$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? '')));
$base = getenv('SITE_URL') ?: 'https://172.18.21.142:3159';

try {
    $model = new SmartlabQrLogin(new Users(1, 1));
    if ($action === 'start') {
        out(array('ok' => true) + $model->start((string) ($_POST['purpose'] ?? 'web'), (string) ($_POST['label'] ?? ''), $base));
    } elseif ($action === 'poll') {
        out(array('ok' => true) + $model->poll((string) ($_GET['challenge'] ?? $_POST['challenge'] ?? '')));
    } elseif ($action === 'cancel') {
        $model->cancel((string) ($_POST['challenge'] ?? ''));
        out(array('ok' => true));
    } elseif ($action === 'confirm') {
        $token = (string) ($_SERVER['HTTP_X_SMARTLAB_TOKEN'] ?? ($_POST['token'] ?? ''));
        $row = SmartlabDrive::lookupToken($token);
        if ($row === null) {
            fail(401, '请先在手机端登录你的 SmartLab 账号。');
        }
        $model->confirm((string) ($_POST['challenge'] ?? ''), (int) $row['user_id'], (int) $row['team_id'], (string) ($_POST['label'] ?? ''));
        out(array('ok' => true, 'message' => '已确认登录，请回到电脑端。'));
    } else {
        fail(400, '不支持的 action：' . $action);
    }
} catch (AppException $e) {
    fail(422, $e->getMessage());
} catch (Throwable $e) {
    fail(500, '服务器处理失败：' . $e->getMessage());
}
