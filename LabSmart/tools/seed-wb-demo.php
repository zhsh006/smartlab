<?php
declare(strict_types=1);

/**
 * One complete WB demo experiment for manual demonstration.
 * Marker: [演示] — only removes previous [演示] WB experiments, never other data.
 * Run: docker cp tools/seed-wb-demo.php CONTAINER:/elabftw/cache/ && docker exec --user nginx CONTAINER php /elabftw/cache/seed-wb-demo.php
 */

chdir('/elabftw');
require 'vendor/autoload.php';

use Elabftw\Elabftw\CreateUploadFromUploadedFile;
use Elabftw\Elabftw\Db;
use Elabftw\Enums\BasePermissions;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\SmartlabWbConditions;
use Elabftw\Models\Uploads;
use Elabftw\Models\Users\Users;
use Symfony\Component\HttpFoundation\File\UploadedFile;

const MARK = '[演示]';
const ASSETS = '/elabftw/cache/demo-assets';
$TEAM = 1;
$PROJECT = 1;
$STUDENT_ID = 2;

$mentor = new Users(1, $TEAM);
$student = new Users($STUDENT_ID, $TEAM);
$Db = Db::getConnection();
$bench = new SmartlabBenchRecords($student);
new SmartlabWbConditions($mentor);
$assay = new SmartlabExperiments($student);

function currentRevision(SmartlabBenchRecords $bench, int $id, string $key): int
{
    $records = $bench->read($id)['records'];
    return (int) ($records[$key]['revision'] ?? 0);
}

function saveRecord(SmartlabBenchRecords $bench, int $id, string $key, array $fields, string $state = 'confirmed'): array
{
    return $bench->save($id, array_merge(array(
        'record_key' => $key, 'record_state' => $state, 'operator' => '演示学生',
        'expected_revision' => currentRevision($bench, $id, $key),
    ), $fields));
}

function createExperiment(Users $user, string $title, array $meta): int
{
    return (new Experiments($user))->create(
        title: $title,
        body: '<p>SmartLab WB 演示数据（自动生成，可删除）。</p>',
        canreadBase: BasePermissions::User,
        canwriteBase: BasePermissions::User,
        metadata: json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
}

function uploadFile(Users $user, int $experimentId, string $src, string $realName): array
{
    if (!is_file($src)) { throw new RuntimeException('missing asset: ' . $src); }
    $experiment = new Experiments($user, $experimentId);
    $experiment->readOne();
    $Uploads = new Uploads($experiment);
    $tmp = '/tmp/seed-' . bin2hex(random_bytes(6));
    if (!copy($src, $tmp)) { throw new RuntimeException('cannot stage ' . $src); }
    try {
        $uploadId = $Uploads->create(new CreateUploadFromUploadedFile(new UploadedFile($tmp, $realName, null, null, true), immutable: 1));
    } finally {
        if (is_file($tmp)) { unlink($tmp); }
    }
    return (new Uploads($experiment, $uploadId))->readOne();
}

function ensureAntibody(Db $Db, int $team, string $code, string $name, string $brand, string $catalogNo): int
{
    $find = $Db->prepare('SELECT id FROM smartlab_materials WHERE team_id = :t AND code = :c');
    $find->bindValue(':t', $team, PDO::PARAM_INT);
    $find->bindValue(':c', $code);
    $Db->execute($find);
    $row = $find->fetch();
    if ($row !== false) { return (int) $row['id']; }
    $ins = $Db->prepare('INSERT INTO smartlab_materials (team_id, code, name, category, unit, brand, catalog_no, created_by) VALUES (:t, :c, :n, "antibody", "uL", :b, :cat, 1)');
    $ins->bindValue(':t', $team, PDO::PARAM_INT);
    $ins->bindValue(':c', $code);
    $ins->bindValue(':n', $name);
    $ins->bindValue(':b', $brand);
    $ins->bindValue(':cat', $catalogNo);
    $Db->execute($ins);
    return (int) $Db->lastInsertId();
}

/* reset only [演示] WB experiments */
$find = $Db->prepare('SELECT id FROM experiments WHERE title LIKE :m AND userid = :u');
$find->bindValue(':m', MARK . ' WB%');
$find->bindValue(':u', $STUDENT_ID, PDO::PARAM_INT);
$Db->execute($find);
foreach ($find->fetchAll() as $row) {
    $old = new Experiments($student, (int) $row['id']);
    $old->readOne();
    $old->destroy();
    echo "removed previous demo WB #{$row['id']}\n";
}

/* catalog: method + antibody (idempotent) */
$wbCond = new SmartlabWbConditions($mentor);
$hasMethod = false;
foreach ($wbCond->methods() as $method) { if (($method['code'] ?? '') === 'TEST-WB-STD') { $hasMethod = true; break; } }
if (!$hasMethod) {
    $wbCond->saveMethod(array(
        'code' => 'TEST-WB-STD', 'name' => '实验室标准 WB（演示）', 'expected_revision' => 0,
        'quantification' => 'BCA', 'loading' => '20 μg / lane', 'gel' => '10% SDS-PAGE',
        'electrophoresis' => '120 V 90 min', 'transfer' => '300 mA 90 min 冰浴',
        'membrane' => 'PVDF 0.45 μm', 'applicability' => '演示数据', 'is_default' => '1',
    ));
}
$antibodyId = ensureAntibody($Db, $TEAM, 'TEST-AB-GAD67', 'Anti-GAD67（演示）', 'Abcam', 'ab26116');
if ($wbCond->latestProfile($antibodyId) === null) {
    $wbCond->saveProfile($antibodyId, array(
        'expected_revision' => 0, 'reviewed' => '1', 'role' => 'primary',
        'host_species' => 'Rabbit', 'reactivity' => 'Human, Mouse', 'target' => 'GAD67',
        'molecular_weight' => '67 kDa', 'clone' => 'EPR19328', 'storage' => '−20℃',
        'wb_dilution' => '1:1000', 'wb_diluent' => '5% BSA TBST', 'wb_incubation' => '4℃ 过夜',
        'lab_dilution' => '1:1500', 'lab_diluent' => '5% BSA TBST', 'lab_incubation' => '4℃ 过夜',
        'method_code' => 'TEST-WB-STD', 'source_note' => '演示说明书（自动生成）',
    ));
}

/* WB experiment */
$wbId = createExperiment($student, MARK . ' WB · GAD67 · 5xFAD 海马', array(
    'experiment_code' => 'EXP-WB-DEMO-001', 'pack_key' => 'western_blot', 'project_code' => 'HPC-2026', 'target_name' => 'GAD67',
));
$assay->initializeAssayPack($wbId, 'western_blot', 'quick', array('project_code' => 'HPC-2026', 'target_name' => 'GAD67'));
$assay->linkProject($wbId, $PROJECT);
$wb5 = uploadFile($student, $wbId, ASSETS . '/WB_GAD67_5s.png', 'WB_GAD67_5s.png');
$wb20 = uploadFile($student, $wbId, ASSETS . '/WB_GAD67_20s.png', 'WB_GAD67_20s.png');

saveRecord($bench, $wbId, 'plan', array(
    'objective' => '验证 5xFAD 小鼠海马 GAD67 蛋白表达相对 WT 的变化',
    'notes' => 'WT 与 5xFAD 各 3 只；内参 GAPDH；ECL 超敏两次曝光。',
));
$setup = $bench->saveWbSetup($wbId, array(
    'antibody_ids' => array($antibodyId), 'method_code' => 'TEST-WB-STD', 'secondary_id' => 0,
    'catalog_token' => $wbCond->catalogToken(), 'expected_revision' => 0,
));
$bench->saveWbActual($wbId, array(
    'conditions' => array('loading' => '25 μg / lane', 'antibody.' . $antibodyId . '.wb_dilution' => '1:1500'),
    'note' => 'GAD67 一抗 1:1500；ECL 超敏，5 s 与 20 s 两次曝光均保留。',
    'record_state' => 'confirmed', 'setup_revision' => (int) $setup['revision'], 'expected_revision' => 0,
));
saveRecord($bench, $wbId, 'result', array(
    'summary' => 'GAD67 在 5xFAD 海马相对 WT 下降约 40%（GAPDH 归一）。',
    'conclusion' => '与既往结果一致，进入 qPCR 进一步验证。',
    'evidence_ids' => array((int) $wb5['id'], (int) $wb20['id']),
    'result_rows' => array(
        array('sample' => 'WT', 'group' => 'WT', 'indicator' => 'GAD67/GAPDH', 'value' => '1.00', 'unit' => 'a.u.'),
        array('sample' => '5xFAD', 'group' => '5xFAD', 'indicator' => 'GAD67/GAPDH', 'value' => '0.58', 'unit' => 'a.u.'),
    ),
));

$ctx = $bench->read($wbId);
echo json_encode(array('WB' => $wbId, 'url' => 'https://172.18.21.142:3159/smartlab-record.php?id=' . $wbId,
    'uploads' => count($ctx['uploads']), 'missing' => count($ctx['missing'])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
