<?php
declare(strict_types=1);
// Only transaction fixtures: no existing scientific record or credential is rewritten.
chdir('/elabftw');
require 'vendor/autoload.php';
use Elabftw\Elabftw\Db;
use Elabftw\Models\Users\Users;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabDataCenter;
use Elabftw\Exceptions\AppException;
$db = Db::getConnection();
$student = new Users(2, 1);
$admin = new Users(1, 1);
$center = new SmartlabDataCenter($student);
$adminCenter = new SmartlabDataCenter($admin);
function insertFixture(string $table, array $values): int
{
    $db = Db::getConnection();
    $keys = array_keys($values);
    $q = $db->prepare('INSERT INTO ' . $table . ' (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_map(fn($k) => ':' . $k, $keys)) . ')');
    foreach ($values as $key => $value) { $q->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR)); }
    $db->execute($q);
    return (int) $db->lastInsertId();
}
function assertRejected(callable $action): void
{
    try { $action(); }
    catch (AppException) { return; }
    throw new RuntimeException('Unauthorized or incomplete operation was accepted.');
}
$usersBefore = (int) $db->q('SELECT COUNT(*) FROM users')->fetchColumn();
$packagesBefore = (int) $db->q('SELECT COUNT(*) FROM smartlab_data_packages')->fetchColumn();
$uploadsBefore = (int) $db->q('SELECT COUNT(*) FROM uploads')->fetchColumn();
assertRejected(fn() => $center->createAgent('Unauthorized test', 2));
if ($center->readAgents() !== array()) { throw new RuntimeException('Student received device configuration.'); }
foreach ($adminCenter->readAgents() as $agent) {
    if (isset($agent['token'])) { throw new RuntimeException('GET response included a credential.'); }
}
echo "Device permissions and secret-free listings: passed\n";
$db->q('START TRANSACTION');
try {
    $user = $db->q('SELECT * FROM users WHERE userid=2')->fetch();
    unset($user['userid']);
    $user['email'] = 'transaction-' . bin2hex(random_bytes(6)) . '@local.invalid';
    $otherId = insertFixture('users', $user);
    $membership = $db->q('SELECT * FROM users2teams WHERE users_id=2 AND teams_id=1')->fetch();
    unset($membership['id']);
    $membership['users_id'] = $otherId;
    $membership['is_admin'] = 0;
    insertFixture('users2teams', $membership);
    $other = new Users($otherId, 1);
    $project = $db->q('SELECT * FROM smartlab_projects WHERE id=1')->fetch();
    unset($project['id']);
    $project['code'] = 'CHECK-' . bin2hex(random_bytes(4));
    $project['name'] = 'Transactional isolated project';
    $project['created_by'] = $otherId;
    $projectId = insertFixture('smartlab_projects', $project);
    insertFixture('smartlab_project_members', array('project_id' => $projectId, 'user_id' => $otherId, 'role' => '学生'));
    $otherExp = (new Experiments($other))->create(title: 'Transactional private experiment');
    insertFixture('smartlab_project_entities', array('project_id' => $projectId, 'entity_type' => 'experiment', 'entity_id' => $otherExp));
    $privatePackage = insertFixture('smartlab_data_packages', array('team_id' => 1, 'source_label' => 'CHECK',
        'package_label' => 'Transactional private package', 'files_json' => '[]', 'status' => 'linked', 'experiment_id' => $otherExp));
    foreach (array($center, $adminCenter) as $reader) {
        if (in_array($privatePackage, array_map('intval', array_column($reader->readPackages('linked'), 'id')), true)) {
            throw new RuntimeException('Cross-project package title leaked.');
        }
    }
    $foreignPending = insertFixture('smartlab_data_packages', array('team_id' => 1, 'source_label' => 'CHECK',
        'package_label' => 'Transactional pending package', 'files_json' => '[]', 'status' => 'pending'));
    insertFixture('smartlab_data_package_readers', array('package_id' => $foreignPending, 'user_id' => $otherId));
    if (in_array($foreignPending, array_map('intval', array_column($center->readPackages(), 'id')), true)) {
        throw new RuntimeException('Unassigned package leaked to another recipient.');
    }
    assertRejected(fn() => $center->linkPackage($foreignPending, 1));
    $badPackage = insertFixture('smartlab_data_packages', array('team_id' => 1, 'source_label' => 'CHECK',
        'package_label' => 'Transactional missing RAW', 'files_json' => '[{"name":"missing.csv","size":1,"sha256":"' . str_repeat('0',64) . '","stored":"missing/file"}]', 'status' => 'pending'));
    insertFixture('smartlab_data_package_readers', array('package_id' => $badPackage, 'user_id' => 2));
    assertRejected(fn() => $center->linkPackage($badPackage, $otherExp));
    assertRejected(fn() => $center->linkPackage($badPackage, 1));
    $status = $db->q('SELECT status FROM smartlab_data_packages WHERE id=' . $badPackage)->fetchColumn();
    if ($status !== 'pending' || $center->lastImportCount !== 0) { throw new RuntimeException('Missing RAW was marked successful.'); }
    if ((int) $db->q('SELECT COUNT(*) FROM uploads')->fetchColumn() !== $uploadsBefore) { throw new RuntimeException('Rejected import changed uploads.'); }
    echo "Two project isolation, admin scope, pending recipients, missing RAW: passed\n";
} finally {
    $db->q('ROLLBACK');
}
if ((int) $db->q('SELECT COUNT(*) FROM users')->fetchColumn() !== $usersBefore
    || (int) $db->q('SELECT COUNT(*) FROM smartlab_data_packages')->fetchColumn() !== $packagesBefore) {
    throw new RuntimeException('Transaction fixtures were not rolled back.');
}
echo "Transaction fixtures rollback: passed\n";
