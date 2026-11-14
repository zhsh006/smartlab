<?php

declare(strict_types=1);

/**
 * SmartLab mobile read API (JSON), used by the WeChat Mini Program and the mobile H5 page.
 * Auth: X-SmartLab-Token (obtained via smartlab-drive-api.php?action=login).
 *   me | experiments | experiment&id=NN
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elabftw\Elabftw\Db;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabDrive;
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

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));
$token = (string) ($_SERVER['HTTP_X_SMARTLAB_TOKEN'] ?? ($_POST['token'] ?? ($_GET['token'] ?? '')));
$row = SmartlabDrive::lookupToken($token);
if ($row === null) {
    fail(401, '登录已失效，请重新登录。');
}
$Users = new Users((int) $row['user_id'], (int) $row['team_id']);
$db = Db::getConnection();

try {
    if ($action === 'me') {
        $req = $db->prepare('SELECT userid, email, firstname, lastname FROM users WHERE userid = :u');
        $req->bindValue(':u', (int) $row['user_id'], PDO::PARAM_INT);
        $db->execute($req);
        $user = $req->fetch() ?: array();
        $user['name'] = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
        out(array('ok' => true, 'user' => $user, 'team' => (int) $row['team_id']));
    }

    if ($action === 'experiments') {
        $req = $db->prepare('SELECT e.id, e.title, e.date, e.userid, ap.pack_key
            FROM experiments e
            LEFT JOIN smartlab_assay_packs ap ON ap.experiment_id = e.id
            WHERE e.team = :team AND e.state != 2
            ORDER BY e.modified_at DESC LIMIT 50');
        $req->bindValue(':team', (int) $row['team_id'], PDO::PARAM_INT);
        $db->execute($req);
        $list = array();
        foreach ($req->fetchAll() as $e) {
            $list[] = array('id' => (int) $e['id'], 'title' => (string) $e['title'], 'date' => (string) $e['date'],
                'template' => (string) ($e['pack_key'] ?? ''), 'mine' => ((int) $e['userid'] === (int) $row['user_id']));
        }
        out(array('ok' => true, 'experiments' => $list));
    }

    if ($action === 'experiment') {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            fail(422, '缺少实验编号。');
        }
        $bench = (new SmartlabBenchRecords($Users))->read($id);
        $req = $db->prepare('SELECT title, date FROM experiments WHERE id = :id');
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $db->execute($req);
        $meta = $req->fetch() ?: array();
        $steps = array();
        foreach ($bench['steps'] as $step) {
            $steps[] = array('label' => (string) ($step['definition']['label'] ?? ''), 'state' => (string) ($step['record']['data']['state'] ?? ''));
        }
        $history = array();
        foreach (array_slice($bench['history'], 0, 10) as $event) {
            $history[] = array('label' => SmartlabBenchRecords::describeRecordKey((string) $event['record_key'], $bench['template']),
                'time' => (string) $event['recorded_at'], 'actor' => (string) ($event['actor_name'] ?? ''));
        }
        out(array('ok' => true, 'experiment' => array(
            'id' => $id,
            'title' => (string) ($meta['title'] ?? ''),
            'date' => (string) ($meta['date'] ?? ''),
            'template' => (string) ($bench['template']['label'] ?? ''),
            'objective' => (string) ($bench['plan']['data']['fields']['objective'] ?? ''),
            'summary' => (string) ($bench['result']['data']['fields']['summary'] ?? ''),
            'conclusion' => (string) ($bench['result']['data']['fields']['conclusion'] ?? ''),
            'uploads' => count($bench['uploads']),
            'steps' => $steps,
            'history' => $history,
        )));
    }

    fail(400, '不支持的 action：' . $action);
} catch (AppException $e) {
    fail(422, $e->getMessage());
} catch (Throwable $e) {
    fail(500, '服务器处理失败：' . $e->getMessage());
}
