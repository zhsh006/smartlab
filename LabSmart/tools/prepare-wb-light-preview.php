<?php
declare(strict_types=1);
// One clearly labelled local UI fixture. No existing scientific data is changed.
chdir('/elabftw'); require 'vendor/autoload.php';
use Elabftw\Elabftw\Db;
use Elabftw\Models\Users\Users;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\SmartlabBenchRecords;
$title = '[操作演示] WB 轻记录（非真实实验）';
$db = Db::getConnection();
$q = $db->prepare('SELECT id FROM experiments WHERE userid=2 AND title=:title AND state=1 ORDER BY id DESC LIMIT 1');
$q->bindValue(':title', $title); $db->execute($q); $id = (int) $q->fetchColumn();
if (!$id) {
    $student = new Users(2, 1);
    $assay = new SmartlabExperiments($student); $bench = new SmartlabBenchRecords($student);
    $id = (new Experiments($student))->create(title: $title);
    $assay->initializeAssayPack($id, 'western_blot'); $assay->linkProject($id, 1);
    $payload = $assay->readAssayPack($id)['payload'];
    $payload['cover']['objective'] = '仅演示减负交互，所有条件与记录均为合成测试数据。';
    $payload['sample']['loading_amount'] = '20 μg / lane';
    $payload['run']['gel_percentage'] = '10%';
    $payload['run']['transfer_condition'] = '湿转 300 mA 90 min（演示）';
    $payload['reagent']['primary_antibody'] = 'CHECK 演示一抗';
    $payload['reagent']['primary_dilution'] = '1:1000';
    $q = $db->prepare('UPDATE smartlab_assay_packs SET payload=:payload WHERE experiment_id=:id');
    $q->bindValue(':payload', json_encode($payload, JSON_THROW_ON_ERROR)); $q->bindValue(':id', $id, PDO::PARAM_INT); $db->execute($q);
    $bench->recordParameters($id, 'western_blot', $payload, array());
}
echo json_encode(array('experiment_id' => $id, 'title' => $title, 'url' => 'https://localhost:3159/smartlab-record.php?id=' . $id), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
