<?php
declare(strict_types=1);
// Local transaction fixtures only. No existing experiment, RAW file or credential is changed.
chdir('/elabftw');
require 'vendor/autoload.php';
$changes = rtrim($argv[1] ?? '/elabftw', '/');
require $changes . '/src/Models/SmartlabBenchRecords.php';
use Elabftw\Elabftw\Db;
use Elabftw\Models\Users\Users;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Exceptions\AppException;

function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function rejected(callable $action): void { try { $action(); } catch (AppException) { return; } throw new RuntimeException('Invalid confirmation was accepted.'); }
function stepView(SmartlabBenchRecords $bench, int $id, string $key): array {
    foreach ($bench->read($id)['steps'] as $step) { if ($step['definition']['key'] === $key) { return $step; } }
    throw new RuntimeException('Missing step.');
}
function lightInput(SmartlabBenchRecords $bench, int $id, string $key, string $state = 'confirmed'): array {
    $step = stepView($bench, $id, $key);
    return array('record_key' => 'step.' . $key, 'record_state' => $state, 'expected_revision' => (int) ($step['record']['revision'] ?? 0),
        'wb_light' => '1', 'expected_reference' => $step['reference_token']);
}
function configureFixture(SmartlabBenchRecords $bench, SmartlabExperiments $assay, int $id, string $loading): void {
    $payload = $assay->readAssayPack($id)['payload'];
    $payload['sample']['loading_amount'] = $loading;
    $payload['run']['gel_percentage'] = '10%';
    $payload['run']['transfer_condition'] = 'CHECK transfer';
    $payload['reagent']['primary_antibody'] = 'CHECK antibody';
    $payload['reagent']['primary_dilution'] = '1:1000';
    $db = Db::getConnection();
    $q = $db->prepare('UPDATE smartlab_assay_packs SET payload=:payload WHERE experiment_id=:id');
    $q->bindValue(':payload', json_encode($payload, JSON_THROW_ON_ERROR)); $q->bindValue(':id', $id, PDO::PARAM_INT); $db->execute($q);
    $bench->recordParameters($id, 'western_blot', $payload, array());
}

$parser = new class { use \Elabftw\Traits\TwigTrait; public function twig(): \Twig\Environment { return $this->getTwig(true); } };
$twig = $parser->twig();
$twig->setLoader(new \Twig\Loader\FilesystemLoader(array($changes . '/src/templates', '/elabftw/src/templates')));
foreach (array('smartlab-record.html', 'smartlab-wb-light.html') as $name) {
    $twig->parse($twig->tokenize(new \Twig\Source(file_get_contents($changes . '/src/templates/' . $name), $name)));
}
echo "Twig syntax: passed\n";
$db = Db::getConnection();
$student = new Users(2, 1);
$bench = new SmartlabBenchRecords($student);
$assay = new SmartlabExperiments($student);
new \Elabftw\Models\SmartlabReviews($student);
$before = $db->q('SELECT id,payload FROM smartlab_bench_records ORDER BY id')->fetchAll();
$experimentsBefore = (int) $db->q('SELECT COUNT(*) FROM experiments')->fetchColumn();
$uploadsBefore = (int) $db->q('SELECT COUNT(*) FROM uploads')->fetchColumn();
$db->q('START TRANSACTION');
try {
    $id = (new Experiments($student))->create(title: 'CHECK-WB-LIGHT transaction only');
    $assay->initializeAssayPack($id, 'western_blot');
    $assay->linkProject($id, 1);
    configureFixture($bench, $assay, $id, '20 μg / lane');
    $beforeRead = (int) $db->q('SELECT COUNT(*) FROM smartlab_bench_records')->fetchColumn();
    $step = stepView($bench, $id, 'loading');
    check($step['unknown'] === array() && !$step['record'], 'References became actual before confirmation.');
    check($step['definition']['fields'][0]['planned'] === '20 μg / lane', 'Loading reference was not carried.');
    check((int) $db->q('SELECT COUNT(*) FROM smartlab_bench_records')->fetchColumn() === $beforeRead, 'Reading created a record.');
    $draft = $bench->save($id, lightInput($bench, $id, 'loading', 'draft'));
    check($draft['data']['parameter_states']['loading'] === 'default_unconfirmed' && $draft['occurred_at'] === null, 'Autosave confirmed a default.');
    $r = $bench->save($id, lightInput($bench, $id, 'loading'));
    check($r['data']['fields']['loading'] === '20 μg / lane' && $r['data']['fields']['gel'] === '10%', 'One-click completion lost reference values.');
    check($r['data']['parameter_states']['loading'] === 'confirmed_default' && $r['occurred_at'] !== null, 'Explicit execution confirmation missing.');
    $time = $r['occurred_at'];
    rejected(fn() => $bench->save($id, lightInput($bench, $id, 'imaging')));
    check(stepView($bench, $id, 'imaging')['unknown'] === array('instrument', 'exposure'), 'Missing-only field list is wrong.');
    echo "Read-only defaults, one-click actual confirmation, unknown rejection: passed\n";

    // A temporary upload metadata row is sufficient to test attachment ownership/merge.
    // Browser acceptance separately uploads real synthetic bytes.
    $source = $db->q('SELECT * FROM uploads LIMIT 1')->fetch(); unset($source['id']);
    $source['item_id'] = $id; $source['type'] = 'experiments'; $source['userid'] = 2;
    $source['real_name'] = 'CHECK-WB-LIGHT.csv'; $source['long_name'] = 'check-wb-light-' . $id . '.csv'; $source['state'] = 1;
    $keys = array_keys($source);
    $q = $db->prepare('INSERT INTO uploads (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_map(fn($k) => ':' . $k, $keys)) . ')');
    foreach ($source as $key => $value) { $q->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR)); }
    $db->execute($q); $upload = (int) $db->lastInsertId();
    $r = $bench->save($id, array_merge(lightInput($bench, $id, 'loading', 'draft'), array('loading' => '30 μg / lane', 'exception' => 'CHECK exception', 'evidence_ids' => array($upload))));
    check($r['data']['state'] === 'draft' && $r['occurred_at'] === null && $r['data']['fields']['gel'] === '10%', 'Changing one field silently confirmed execution or lost another field.');
    $r = $bench->save($id, lightInput($bench, $id, 'loading'));
    check($r['data']['fields']['loading'] === '30 μg / lane' && $r['data']['parameter_states']['loading'] === 'overridden', 'Changed value was overwritten by default.');
    check($r['data']['fields']['exception'] === 'CHECK exception' && $r['data']['evidence_ids'] === array($upload) && $r['occurred_at'] === $time, 'Confirmation lost an annotation or operation time.');
    $stale = lightInput($bench, $id, 'loading'); $stale['expected_revision'] = 1;
    rejected(fn() => $bench->save($id, $stale));
    $r = $bench->save($id, array_merge(lightInput($bench, $id, 'loading', 'draft'), array('exception' => 'CHECK later annotation')));
    check($r['data']['state'] === 'confirmed' && $r['occurred_at'] === $time && $r['data']['evidence_ids'] === array($upload), 'Adding annotation undid execution.');
    rejected(fn() => $bench->save($id, array_merge(lightInput($bench, $id, 'loading', 'draft'), array('evidence_ids' => array(2147483647)))));
    $r = $bench->save($id, array_merge(lightInput($bench, $id, 'loading', 'draft'), array('loading' => '')));
    check($r['data']['parameter_states']['loading'] === 'unknown', 'Explicit blank was replaced by reference.');
    rejected(fn() => $bench->save($id, lightInput($bench, $id, 'loading')));
    $bench->save($id, array_merge(lightInput($bench, $id, 'loading'), array('loading' => '30 μg / lane')));
    echo "Selective edits, explicit blank, evidence merge, annotation-only save, revision conflict: passed\n";

    $staleReference = lightInput($bench, $id, 'loading');
    configureFixture($bench, $assay, $id, '25 μg / lane');
    rejected(fn() => $bench->save($id, $staleReference));
    $r = $bench->save($id, array_merge(lightInput($bench, $id, 'loading', 'draft'), array('exception' => 'CHECK after reference changed')));
    check($r['data']['reference_values']['loading'] === '20 μg / lane', 'Annotation rewrote the execution reference.');
    $r = $bench->save($id, array_merge(lightInput($bench, $id, 'transfer'), array('entry_mode' => 'repeat')));
    $repeatTime = $r['occurred_at'];
    $r = $bench->save($id, array_merge(lightInput($bench, $id, 'transfer', 'draft'), array('exception' => 'CHECK transfer photo')));
    check($r['data']['state'] === 'confirmed' && $r['occurred_at'] === $repeatTime, 'Adding a photo recorded another physical repetition.');
    $antibody = stepView($bench, $id, 'antibody');
    check($antibody['unknown'] === array('incubation'), 'Known antibody and dilution were requested again.');
    $r = $bench->save($id, array_merge(lightInput($bench, $id, 'antibody'), array('incubation' => 'CHECK incubation')));
    check($r['data']['fields']['dilution'] === '1:1000', 'Antibody dilution reference was lost.');
    $bench->save($id, lightInput($bench, $id, 'finish'));
    check(!isset($bench->read($id)['records']['result']), 'Finishing forced a report/conclusion.');
    $bench->save($id, array_merge(lightInput($bench, $id, 'loading', 'draft'), array('exception' => 'CHECK post-completion annotation')));
    $context = $bench->read($id);
    check(SmartlabBenchRecords::progress($context['template'], $context['records'])['finished'], 'Post-completion annotation reopened the experiment.');
    $bench->save($id, array_merge(lightInput($bench, $id, 'imaging', 'draft'), array('instrument' => 'CHECK late correction')));
    $context = $bench->read($id);
    check(!SmartlabBenchRecords::progress($context['template'], $context['records'])['finished'], 'A later draft correction looked completed.');
    $bench->save($id, array_merge(lightInput($bench, $id, 'imaging'), array('exposure' => 'CHECK actual exposure')));
    $context = $bench->read($id);
    check(SmartlabBenchRecords::progress($context['template'], $context['records'])['finished'], 'Confirmed correction required another report.');
    // Existing discussion submission freezes references and actual revision IDs.
    $assay->submitReview($id, 1, 'CHECK transactional discussion');
    $flow = (int) $db->q('SELECT id FROM smartlab_review_flows WHERE entity_id=' . $id . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    $bench->recordSubmission($id, $flow, 'discussion', 'CHECK snapshot');
    configureFixture($bench, $assay, $id, '40 μg / lane');
    $snapshot = $bench->read($id, $flow);
    check($snapshot['steps'][1]['definition']['fields'][0]['planned'] === '25 μg / lane', 'Submitted reference changed with live configuration.');
    check($snapshot['records']['step.loading']['data']['reference_values']['loading'] === '20 μg / lane', 'Execution reference snapshot was rewritten.');
    echo "Reference-change conflict, known-condition carry, finish without report, frozen snapshot: passed\n";

    $context = $bench->read($id); $view = stepView($bench, $id, 'loading');
    $entity = new Experiments($student, $id); $entity->readOne();
    $session = new class { public function get(string $name): string { return str_repeat('a', 64); } };
    $html = $twig->render('smartlab-wb-light.html', array('Entity' => $entity, 'App' => (object) array('Session' => $session), 'bench' => $context,
        'step' => $view, 'record' => $view['record'], 'd' => $view['definition'], 'done' => true, 'loop' => array('index' => 2),
        'smartlabAvailableSamplesArr' => array(), 'smartlabAvailableLotsArr' => array(), 'smartlabAssayPresetCatalogArr' => array()));
    check(str_contains($html, '30 μg / lane') && str_contains($html, 'data-wb-light'), 'Light form did not render preserved values.');
    echo "Twig light form rendering: passed\n";
} finally { $db->q('ROLLBACK'); }
check($db->q('SELECT id,payload FROM smartlab_bench_records ORDER BY id')->fetchAll() === $before, 'Existing record payload or history changed.');
check((int) $db->q('SELECT COUNT(*) FROM experiments')->fetchColumn() === $experimentsBefore && (int) $db->q('SELECT COUNT(*) FROM uploads')->fetchColumn() === $uploadsBefore, 'Transaction fixtures remained.');
echo "Rollback and existing record/history preservation: passed\n";
