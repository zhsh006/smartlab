<?php
declare(strict_types=1);
// Run in the local application container after migration 003. All fixtures roll back.
chdir('/elabftw'); require 'vendor/autoload.php';
use Elabftw\Elabftw\Db;
use Elabftw\Models\Users\Users;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabWbConditions;
use Elabftw\Exceptions\AppException;
use Elabftw\Services\SmartlabAntibodyDatasheet;
function checkWb(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function denyWb(callable $action): void { try { $action(); } catch (AppException) { return; } throw new RuntimeException('Invalid write/read was accepted.'); }
$db = Db::getConnection(); $student = new Users(2,1); $admin = new Users(1,1);
$assay = new SmartlabExperiments($student); $bench = new SmartlabBenchRecords($student);
$catalog = new SmartlabWbConditions($admin); $studentCatalog = new SmartlabWbConditions($student);
new \Elabftw\Models\SmartlabReviews($student);
$before = $db->q('SELECT id,payload FROM smartlab_bench_records ORDER BY id')->fetchAll();
$experimentsBefore = (int) $db->q('SELECT COUNT(*) FROM experiments')->fetchColumn();
$profilesBefore = (int) $db->q('SELECT COUNT(*) FROM smartlab_antibody_wb_profiles')->fetchColumn();
$db->q('START TRANSACTION');
try {
    $db->q('INSERT INTO smartlab_materials (team_id,code,name,category,unit,created_by) VALUES (1,"CHECK-WB-CONDITIONS","CHECK antibody (synthetic)","antibody","uL",1)');
    $material = $db->lastInsertId();
    $method = array('code'=>'CHECK-WB','name'=>'CHECK method (synthetic)','expected_revision'=>0,'quantification'=>'CHECK BCA','loading'=>'20 μg / lane','gel'=>'CHECK gel','transfer'=>'CHECK transfer');
    $catalog->saveMethod($method);
    $sheet = SmartlabAntibodyDatasheet::pasted("Host Species: Rabbit\nSpecies Reactivity: Mouse\nWB: 1:1000–1:4000\nIF: 1:200");
    $profile = array_merge($sheet['fields'],array('reviewed'=>'1','expected_revision'=>0,'role'=>'primary','method_code'=>'CHECK-WB','wb_incubation'=>'CHECK incubation'));
    $profileId = $catalog->saveProfile($material,$profile,$sheet);
    checkWb(hash_equals($sheet['hash'],hash('sha256',$catalog->source($profileId)['source_bytes'])),'Manual original changed.');
    denyWb(fn()=>$studentCatalog->saveProfile($material,$profile,$sheet));
    denyWb(fn()=>$studentCatalog->saveMethod($method));
    $id = (new Experiments($student))->create(title:'CHECK-WB-CONDITIONS transaction only');
    $assay->initializeAssayPack($id,'western_blot'); $assay->linkProject($id,1);
    $token = $studentCatalog->catalogToken();
    $setup = $bench->saveWbSetup($id,array('antibody_ids'=>array($material),'method_code'=>'','catalog_token'=>$token,'expected_revision'=>0));
    $view = $bench->wbView($bench->read($id));
    checkWb(!$view['actual'] && $view['rows']['loading']['state']==='default_unconfirmed','Selection created actual execution.');
    checkWb($view['snapshot']['method']['code']==='CHECK-WB','Mapped method was not selected.');
    $input = array('setup_revision'=>1,'expected_revision'=>0,'conditions'=>array('loading'=>'30 μg / lane'));
    $draft = $bench->saveWbActual($id,$input);
    checkWb($draft['data']['state']==='draft' && $draft['occurred_at']===null,'Autosave confirmed execution.');
    $input['expected_revision']=$draft['revision']; $input['record_state']='confirmed';
    $actual = $bench->saveWbActual($id,$input);
    checkWb($actual['data']['fields']['loading']==='30 μg / lane' && $actual['data']['parameter_states']['loading']==='overridden','Selective change was lost.');
    checkWb($actual['data']['fields']['antibody.'.$material.'.wb_dilution']==='' && $actual['data']['parameter_states']['antibody.'.$material.'.wb_dilution']==='unknown','A dilution range became an invented actual value.');
    checkWb(SmartlabBenchRecords::progress($bench->read($id)['template'],$bench->read($id)['records'])['finished'],'One completion did not finish the batch.');
    $note = $bench->saveWbActual($id,array('setup_revision'=>1,'expected_revision'=>$actual['revision'],'note'=>'CHECK annotation'));
    checkWb($note['data']['state']==='confirmed' && $note['occurred_at']===$actual['occurred_at'],'Annotation manufactured a new operation.');
    denyWb(fn()=>$bench->saveWbActual($id,$input));
    $profile['expected_revision']=1; $profile['wb_dilution']='1:2000'; $catalog->saveProfile($material,$profile);
    $method['expected_revision']=1; $method['loading']='25 μg / lane'; $catalog->saveMethod($method);
    $frozen = $bench->wbView($bench->read($id));
    checkWb($frozen['snapshot']['antibodies'][0]['profile_id']==$profileId && $frozen['rows']['loading']['value']==='20 μg / lane','Library update rewrote an existing experiment.');
    checkWb($catalog->source($profileId)['source_bytes']===$sheet['bytes'],'Old manual version was overwritten.');
    denyWb(fn()=>$bench->saveWbSetup($id,array('antibody_ids'=>array($material),'method_code'=>'','catalog_token'=>$token,'expected_revision'=>1)));
    $bench->saveWbSetup($id,array('antibody_ids'=>array($material),'method_code'=>'','catalog_token'=>$studentCatalog->catalogToken(),'expected_revision'=>1));
    checkWb(!$bench->wbView($bench->read($id))['current_actual'],'New selection reused old confirmation.');
    echo "Persistence, snapshot versions, defaults/actual, ranges, selective edits, annotation, concurrency, student write isolation: passed\n";
} finally { $db->q('ROLLBACK'); }
checkWb($before===$db->q('SELECT id,payload FROM smartlab_bench_records ORDER BY id')->fetchAll(),'Existing records were changed.');
checkWb($experimentsBefore===(int)$db->q('SELECT COUNT(*) FROM experiments')->fetchColumn(),'Experiment fixtures were not rolled back.');
checkWb($profilesBefore===(int)$db->q('SELECT COUNT(*) FROM smartlab_antibody_wb_profiles')->fetchColumn(),'Profile fixtures were not rolled back.');
echo "Original records unchanged; fixture rollback passed\n";
