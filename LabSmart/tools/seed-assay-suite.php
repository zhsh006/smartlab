<?php
declare(strict_types=1);

/**
 * SmartLab assay test-data suite: one complete WB, qPCR and IF experiment.
 *
 * Creates only clearly labelled [测试数据] experiments (student userid 2, team 1)
 * and removes earlier ones first, so it is safe to re-run. It exercises the real
 * models/services: SmartlabBenchRecords (plan/steps/result, wb.setup + wb.actual,
 * assay.workspace / assay.file / assay.note / assay.qpcr), SmartlabQpcrImport and
 * SmartlabAssayWorkspace, plus real file uploads via Uploads.
 *
 * Run inside the app container as the cache owner:
 *   docker cp tools/seed-assay-suite.php CONTAINER:/elabftw/cache/seed-assay-suite.php
 *   docker cp tools/demo-assets CONTAINER:/elabftw/cache/
 *   docker exec --user nginx CONTAINER php /elabftw/cache/seed-assay-suite.php
 */

chdir('/elabftw');
require 'vendor/autoload.php';

use Elabftw\Elabftw\CreateUploadFromUploadedFile;
use Elabftw\Elabftw\Db;
use Elabftw\Enums\BasePermissions;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\SmartlabReviews;
use Elabftw\Models\SmartlabWbConditions;
use Elabftw\Models\Uploads;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabAssayWorkspace;
use Elabftw\Services\SmartlabQpcrImport;
use Symfony\Component\HttpFoundation\File\UploadedFile;

const MARK = '[测试数据]';
const ASSETS = '/elabftw/cache/demo-assets';

$TEAM = 1;
$PROJECT = 1;
$STUDENT_ID = 2;

$mentor = new Users(1, $TEAM);
$student = new Users($STUDENT_ID, $TEAM);
$Db = Db::getConnection();

// Runtime tables are created idempotently by the model constructors.
$bench = new SmartlabBenchRecords($student);
new SmartlabWbConditions($mentor);
new SmartlabReviews($student);
$assay = new SmartlabExperiments($student);

function uuidv4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function currentRevision(SmartlabBenchRecords $bench, int $id, string $key): int
{
    $records = $bench->read($id)['records'];
    return (int) ($records[$key]['revision'] ?? 0);
}

/** Append a plan/step/result record through the real model. */
function saveRecord(SmartlabBenchRecords $bench, int $id, string $key, array $fields, string $state = 'confirmed'): array
{
    return $bench->save($id, array_merge(array(
        'record_key' => $key,
        'record_state' => $state,
        'operator' => '演示学生',
        'expected_revision' => currentRevision($bench, $id, $key),
    ), $fields));
}

/** Move/complete the qPCR/IF workspace, always using the freshest token/revision. */
function saveWorkspace(SmartlabBenchRecords $bench, int $id, string $stage, array $fields, string $state): array
{
    $view = SmartlabAssayWorkspace::view($bench->read($id));
    return $bench->saveAssayWorkspace($id, array(
        'source_token' => $view['source_token'],
        'expected_revision' => $view['revision'],
        'stage' => $stage,
        'fields' => $fields,
        'record_state' => $state,
    ));
}

function createExperiment(Users $user, string $title, array $meta): int
{
    $Experiments = new Experiments($user);
    return $Experiments->create(
        title: $title,
        body: '<p>SmartLab 测试数据（自动生成，可删除）。</p>',
        canreadBase: BasePermissions::User,
        canwriteBase: BasePermissions::User,
        metadata: json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
}

function uploadFile(Users $user, int $experimentId, string $src, string $realName): array
{
    if (!is_file($src)) {
        throw new RuntimeException('missing asset: ' . $src);
    }
    $experiment = new Experiments($user, $experimentId);
    $experiment->readOne();
    $Uploads = new Uploads($experiment);
    $tmp = '/tmp/seed-' . bin2hex(random_bytes(6));
    if (!copy($src, $tmp)) {
        throw new RuntimeException('cannot stage ' . $src);
    }
    try {
        $uploadId = $Uploads->create(new CreateUploadFromUploadedFile(
            new UploadedFile($tmp, $realName, null, null, true),
            immutable: 1,
        ));
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
    return (new Uploads($experiment, $uploadId))->readOne();
}

/** Create (or reuse) an antibody material usable by SmartlabWbConditions. */
function ensureAntibody(Db $Db, int $team, string $code, string $name, string $brand, string $catalogNo): int
{
    $find = $Db->prepare('SELECT id FROM smartlab_materials WHERE team_id = :t AND code = :c');
    $find->bindValue(':t', $team, PDO::PARAM_INT);
    $find->bindValue(':c', $code);
    $Db->execute($find);
    $row = $find->fetch();
    if ($row !== false) {
        return (int) $row['id'];
    }
    $ins = $Db->prepare('INSERT INTO smartlab_materials (team_id, code, name, category, unit, brand, catalog_no, created_by)
        VALUES (:t, :c, :n, "antibody", "uL", :b, :cat, 1)');
    $ins->bindValue(':t', $team, PDO::PARAM_INT);
    $ins->bindValue(':c', $code);
    $ins->bindValue(':n', $name);
    $ins->bindValue(':b', $brand);
    $ins->bindValue(':cat', $catalogNo);
    $Db->execute($ins);
    return (int) $Db->lastInsertId();
}

/* ---------------------------------------------------------------- reset ---- */
$find = $Db->prepare('SELECT id FROM experiments WHERE title LIKE :m AND userid = :u');
$find->bindValue(':m', MARK . '%');
$find->bindValue(':u', $STUDENT_ID, PDO::PARAM_INT);
$Db->execute($find);
foreach ($find->fetchAll() as $row) {
    $old = new Experiments($student, (int) $row['id']);
    $old->readOne();
    $old->destroy();
    echo "removed previous test experiment #{$row['id']}\n";
}

$created = array();

/* ------------------------------------------------------------------ WB ----- */
$wbId = createExperiment($student, MARK . ' WB · GAD67 · 5xFAD 海马', array(
    'experiment_code' => 'EXP-WB-TEST-001', 'pack_key' => 'western_blot', 'project_code' => 'HPC-2026', 'target_name' => 'GAD67',
));
$assay->initializeAssayPack($wbId, 'western_blot', 'quick', array('project_code' => 'HPC-2026', 'target_name' => 'GAD67'));
$assay->linkProject($wbId, $PROJECT);
$wb5 = uploadFile($student, $wbId, ASSETS . '/WB_GAD67_5s.png', 'WB_GAD67_5s.png');
$wb20 = uploadFile($student, $wbId, ASSETS . '/WB_GAD67_20s.png', 'WB_GAD67_20s.png');
$wbEv = array((int) $wb5['id'], (int) $wb20['id']);

saveRecord($bench, $wbId, 'plan', array(
    'objective' => '验证 5xFAD 小鼠海马 GAD67 蛋白表达相对 WT 的变化',
    'notes' => 'WT 与 5xFAD 各 3 只；内参 GAPDH；ECL 超敏两次曝光。',
));

// Antibody + method catalog (maintained by the assistant/入库人员). Idempotent.
$wbCond = new SmartlabWbConditions($mentor);
$hasMethod = false;
foreach ($wbCond->methods() as $method) {
    if (($method['code'] ?? '') === 'TEST-WB-STD') {
        $hasMethod = true;
        break;
    }
}
if (!$hasMethod) {
    $wbCond->saveMethod(array(
        'code' => 'TEST-WB-STD', 'name' => '实验室标准 WB（测试）', 'expected_revision' => 0,
        'quantification' => 'BCA', 'loading' => '20 μg / lane', 'gel' => '10% SDS-PAGE',
        'electrophoresis' => '120 V 90 min', 'transfer' => '300 mA 90 min 冰浴',
        'membrane' => 'PVDF 0.45 μm', 'applicability' => '测试数据，非实验室 SOP', 'is_default' => '1',
    ));
}
$antibodyId = ensureAntibody($Db, $TEAM, 'TEST-AB-GAD67', 'Anti-GAD67（测试）', 'Abcam', 'ab26116');
if ($wbCond->latestProfile($antibodyId) === null) {
    $wbCond->saveProfile($antibodyId, array(
        'expected_revision' => 0, 'reviewed' => '1', 'role' => 'primary',
        'host_species' => 'Rabbit', 'reactivity' => 'Human, Mouse', 'target' => 'GAD67',
        'molecular_weight' => '67 kDa', 'clone' => 'EPR19328', 'storage' => '−20℃',
        'wb_dilution' => '1:1000', 'wb_diluent' => '5% BSA TBST', 'wb_incubation' => '4℃ 过夜',
        'lab_dilution' => '1:1500', 'lab_diluent' => '5% BSA TBST', 'lab_incubation' => '4℃ 过夜',
        'method_code' => 'TEST-WB-STD', 'source_note' => '测试说明书（自动生成）',
    ));
}

$setup = $bench->saveWbSetup($wbId, array(
    'antibody_ids' => array($antibodyId), 'method_code' => 'TEST-WB-STD', 'secondary_id' => 0,
    'catalog_token' => $wbCond->catalogToken(), 'expected_revision' => 0,
));
$bench->saveWbActual($wbId, array(
    'conditions' => array(
        'loading' => '25 μg / lane',
        'antibody.' . $antibodyId . '.wb_dilution' => '1:1500',
    ),
    'note' => 'GAD67 一抗 1:1500；ECL 超敏，5 s 与 20 s 两次曝光均保留。',
    'record_state' => 'confirmed',
    'setup_revision' => (int) $setup['revision'],
    'expected_revision' => 0,
));
saveRecord($bench, $wbId, 'result', array(
    'summary' => 'GAD67 在 5xFAD 海马相对 WT 下降约 40%（GAPDH 归一）。',
    'conclusion' => '与既往结果一致，进入 qPCR 进一步验证。',
    'evidence_ids' => $wbEv,
    'result_rows' => array(
        array('sample' => 'WT', 'group' => 'WT', 'indicator' => 'GAD67/GAPDH', 'value' => '1.00', 'unit' => 'a.u.'),
        array('sample' => '5xFAD', 'group' => '5xFAD', 'indicator' => 'GAD67/GAPDH', 'value' => '0.58', 'unit' => 'a.u.'),
    ),
));
$created['WB'] = $wbId;

/* ---------------------------------------------------------------- qPCR ----- */
$qpcrId = createExperiment($student, MARK . ' qPCR · Gad1/Gapdh · 5xFAD 海马', array(
    'experiment_code' => 'EXP-QPCR-TEST-001', 'pack_key' => 'qpcr', 'project_code' => 'HPC-2026', 'target_name' => 'Gad1',
));
$assay->initializeAssayPack($qpcrId, 'qpcr', 'quick', array('project_code' => 'HPC-2026', 'target_name' => 'Gad1'));
$assay->linkProject($qpcrId, $PROJECT);
$csv = uploadFile($student, $qpcrId, ASSETS . '/qpcr-ct-96well.csv', 'qpcr-ct-96well.csv');
$csvId = (int) $csv['id'];

saveRecord($bench, $qpcrId, 'plan', array(
    'objective' => '相对定量 5xFAD 与 WT 海马 Gad1（GAD67）表达差异',
    'notes' => 'Gapdh 内参；每样本 3 复孔；ΔΔCt 法。',
));
saveRecord($bench, $qpcrId, 'step.prepare', array(
    'sample_label' => '5xFAD / WT 海马 cDNA（测试）', 'groups' => 'WT n=4；5xFAD n=4；每样本 3 复孔',
));
saveRecord($bench, $qpcrId, 'step.reaction', array(
    'target' => 'Gad1', 'reference' => 'Gapdh', 'volume' => '20 μL', 'reagent_lot_id' => '',
));
saveRecord($bench, $qpcrId, 'step.acquire', array(
    'instrument' => 'QuantStudio 5（测试）', 'settings' => '95℃30s；95℃5s/60℃34s×40；熔解曲线',
));
saveRecord($bench, $qpcrId, 'step.finish', array('note' => 'Ct 原始值已导入，未检出值保持原样。'));

$wsQpcr = array(
    'prepare.sample_label' => '5xFAD / WT 海马 cDNA（测试）',
    'prepare.groups' => 'WT n=4；5xFAD n=4；每样本 3 复孔',
    'reaction.target' => 'Gad1 (GAD67)',
    'reaction.reference' => 'Gapdh',
    'reaction.primer' => 'mGad1-F/R、mGapdh-F/R',
    'reaction.kit' => 'TB Green Premix Ex Taq',
    'reaction.volume' => '20 μL',
    'acquire.instrument' => 'QuantStudio 5（测试）',
    'acquire.settings' => '95℃30s；95℃5s/60℃34s×40；熔解曲线',
);
foreach (array('prepare', 'reaction', 'acquire') as $stage) {
    saveWorkspace($bench, $qpcrId, $stage, $wsQpcr, 'draft');
}
saveWorkspace($bench, $qpcrId, 'analysis', $wsQpcr, 'confirmed');

$bench->saveAssayFile($qpcrId, array(
    'file_id' => $csvId, 'sample' => '5xFAD / WT 海马 cDNA', 'plate' => 'P1', 'run' => 'Run1',
    'expected_revision' => currentRevision($bench, $qpcrId, 'assay.file.' . $csvId),
));
$bytes = file_get_contents(ASSETS . '/qpcr-ct-96well.csv');
$preview = SmartlabQpcrImport::preview((string) $bytes, 'qpcr-ct-96well.csv');
$bench->saveAssayCsv($qpcrId, array(
    'file_id' => $csvId, 'sheet' => $preview['sheet'], 'expected_hash' => $preview['hash'],
    'mapping' => $preview['suggested'], 'expected_revision' => currentRevision($bench, $qpcrId, 'assay.qpcr.' . $csvId),
));
$bench->saveAssayNote($qpcrId, array(
    'stage' => 'acquire', 'note_id' => uuidv4(),
    'note' => '5XFAD4 的 Gad1 有一孔未检出，按原值保留；WT4 的 Gapdh 一孔 N/A。',
    'expected_revision' => 0,
));
saveRecord($bench, $qpcrId, 'result', array(
    'summary' => '以 Gapdh 归一，5xFAD 海马 Gad1 相对 WT 约为 0.35 倍（2^-ΔΔCt）。',
    'conclusion' => 'qPCR 与 WB 趋势一致；未检出孔不参与定量。',
    'evidence_ids' => array($csvId),
    'result_rows' => array(
        array('sample' => 'WT', 'group' => 'WT', 'indicator' => 'Gad1 (rel.)', 'value' => '1.00', 'unit' => 'fold'),
        array('sample' => '5xFAD', 'group' => '5xFAD', 'indicator' => 'Gad1 (rel.)', 'value' => '0.35', 'unit' => 'fold'),
    ),
));
$created['qPCR'] = $qpcrId;

/* ------------------------------------------------------------------ IF ----- */
$ifId = createExperiment($student, MARK . ' 免疫荧光 · PV / NeuN · 5xFAD 海马', array(
    'experiment_code' => 'EXP-IF-TEST-001', 'pack_key' => 'if', 'project_code' => 'HPC-2026', 'target_name' => 'PV / NeuN',
));
$assay->initializeAssayPack($ifId, 'if', 'quick', array('project_code' => 'HPC-2026', 'target_name' => 'PV / NeuN'));
$assay->linkProject($ifId, $PROJECT);
$ifImg = uploadFile($student, $ifId, ASSETS . '/IF_PV_NeuN_panel.png', 'IF_PV_NeuN_panel.png');
$ifId2 = (int) $ifImg['id'];

saveRecord($bench, $ifId, 'plan', array(
    'objective' => '观察 5xFAD 海马 PV 与 NeuN 共染变化',
    'notes' => 'PV 488 / NeuN 594；DAPI 核染；40x 油镜。',
));
saveRecord($bench, $ifId, 'step.prepare', array(
    'sample_label' => '5xFAD / WT 海马冰冻切片（测试）', 'groups' => 'WT n=3；5xFAD n=3',
));
saveRecord($bench, $ifId, 'step.staining', array(
    'antibody' => 'Anti-PV / Anti-NeuN（测试）', 'dilution' => '1:500', 'incubation' => '4℃ 过夜',
));
saveRecord($bench, $ifId, 'step.acquire', array(
    'instrument' => 'Zeiss LSM 880（测试）', 'settings' => '405 / 488 / 594 三通道；Z-stack 5 层',
));
saveRecord($bench, $ifId, 'step.finish', array('note' => '代表性 merge 图已上传。'));

$wsIf = array(
    'prepare.sample_label' => '5xFAD / WT 海马冰冻切片（测试）',
    'prepare.groups' => 'WT n=3；5xFAD n=3',
    'prepare.substrate' => '海马冰冻切片 20 μm（测试）',
    'staining.antibody' => 'Anti-PV / Anti-NeuN（测试）',
    'staining.dilution' => '1:500',
    'staining.incubation' => '4℃ 过夜',
    'staining.secondary' => 'Alexa Fluor 488 / 594（测试）',
    'staining.nuclear' => 'DAPI 1:1000',
    'acquire.instrument' => 'Zeiss LSM 880（测试）',
    'acquire.objective' => '40x oil',
    'acquire.exposure' => 'Alexa488 500 ms / DAPI 200 ms',
);
foreach (array('prepare', 'staining', 'acquire') as $stage) {
    saveWorkspace($bench, $ifId, $stage, $wsIf, 'draft');
}
saveWorkspace($bench, $ifId, 'analysis', $wsIf, 'confirmed');

$bench->saveAssayFile($ifId, array(
    'file_id' => $ifId2, 'sample' => '5xFAD 海马', 'substrate' => '冰冻切片 20 μm',
    'field' => 'CA1', 'channel' => 'PV488/NeuN594/DAPI', 'pixel_size' => '0.10 μm/px',
    'expected_revision' => currentRevision($bench, $ifId, 'assay.file.' . $ifId2),
));
$bench->saveAssayNote($ifId, array(
    'stage' => 'acquire', 'note_id' => uuidv4(),
    'note' => 'CA1 区 PV 阳性细胞计数下降，NeuN 总数基本不变（待定量）。',
    'expected_revision' => 0,
));
saveRecord($bench, $ifId, 'result', array(
    'summary' => '5xFAD 海马 CA1 区 PV 阳性细胞减少，NeuN 总量无明显变化。',
    'conclusion' => '与抑制性中间神经元受累一致，需 ImageJ 定量确认。',
    'evidence_ids' => array($ifId2),
    'result_rows' => array(
        array('sample' => 'WT', 'group' => 'WT', 'indicator' => 'PV+/mm²', 'value' => '120', 'unit' => 'cells/mm²'),
        array('sample' => '5xFAD', 'group' => '5xFAD', 'indicator' => 'PV+/mm²', 'value' => '72', 'unit' => 'cells/mm²'),
    ),
));
$created['IF'] = $ifId;

/* -------------------------------------------------------------- summary ---- */
foreach ($created as $label => $id) {
    $ctx = $bench->read($id);
    printf(
        "%-5s experiment=%-3d records=%-3d uploads=%-2d missing=%d\n",
        $label,
        $id,
        count($ctx['records']),
        count($ctx['uploads']),
        count($ctx['missing']),
    );
}
echo json_encode($created, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
