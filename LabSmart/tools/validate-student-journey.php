<?php
declare(strict_types=1);
// Focused regression checks. All database fixtures are rolled back.
chdir('/elabftw'); require 'vendor/autoload.php';
$candidate = rtrim($argv[1] ?? '/elabftw', '/');
require $candidate . '/src/Models/SmartlabDashboard.php';
require $candidate . '/src/Services/SmartlabQpcrImport.php';
use Elabftw\Elabftw\Db;
use Elabftw\Models\Users\Users;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabDashboard;
use Elabftw\Services\SmartlabQpcrImport;
function auditCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$db=Db::getConnection();
$row=$db->q('SELECT u.userid, ut.teams_id FROM users u JOIN users2teams ut ON u.userid=ut.users_id WHERE u.email="preview.student@local.test" LIMIT 1')->fetch();
if (!$row) { throw new RuntimeException('The preview student fixture is required.'); }
$student=new Users((int)$row['userid'],(int)$row['teams_id']);
$bench=new SmartlabBenchRecords($student); $assay=new SmartlabExperiments($student); $dashboard=new SmartlabDashboard($student);
$before=(int)$db->q('SELECT COUNT(*) FROM experiments')->fetchColumn();
$db->q('START TRANSACTION');
try {
    foreach (array('western_blot'=>'wb.progress', 'qpcr'=>'assay.workspace', 'if'=>'assay.workspace') as $type=>$key) {
        $id=(new Experiments($student))->create(title:'[事务模拟验收] progress '.$type);
        $assay->initializeAssayPack($id,$type);
        if ($type==='western_blot') {
            $bench->saveWbProgress($id,array('stage'=>'antibody','expected_revision'=>0));
        } else {
            $payload=json_encode(array('state'=>'draft','stage'=>'acquire','fields'=>array()),JSON_THROW_ON_ERROR);
            $query=$db->prepare('INSERT INTO smartlab_bench_records (experiment_id,record_key,revision,event_type,payload,actor_userid,recorded_at,entry_mode) VALUES (:id,:key,1,"progress_noted",:payload,:user,UTC_TIMESTAMP(6),"live")');
            $query->execute(array('id'=>$id,'key'=>$key,'payload'=>$payload,'user'=>(int)$student->userid));
        }
        $summary=$dashboard->readBenchSummaries(array($id))[$id];
        auditCheck(isset($summary['step_records'][$key]), 'Dashboard lost '.$key);
        $p=SmartlabBenchRecords::progress(SmartlabBenchRecords::templates()[$type],$summary['step_records']);
        auditCheck($p['has_stage'] && !$p['finished'], 'Progress incorrectly appears unstarted/finished: '.$type);
        auditCheck($p['resume_key']===($type==='western_blot'?'antibody':'acquire'), 'Resume stage differs: '.$type);
    }
    echo "WB/qPCR/IF dashboard stage and draft confirmation separation: passed\n";
} finally { $db->q('ROLLBACK'); }
auditCheck((int)$db->q('SELECT COUNT(*) FROM experiments')->fetchColumn()===$before,'A regression experiment remained.');
$csv="Well,Sample,Target,Ct,Plate,Run\r\nA01,C01,TARGET_A,24.20,SIM-P01,SIM-R01\r\nA02,NTC,TARGET_A,Undetermined,SIM-P01,SIM-R01\r\nA03,C02,TARGET_A,,SIM-P01,SIM-R01\r\n\r\n";
$preview=SmartlabQpcrImport::preview($csv,'SIM-regression.csv');
$project=SmartlabQpcrImport::project($preview,$preview['suggested']);
auditCheck(count($project['rows'])===3,'Blank trailing CSV row was not ignored.');
auditCheck($project['rows'][0]['ct']==='24.20','Numeric source precision changed.');
auditCheck($project['rows'][1]['ct_state']==='unknown' && $project['rows'][1]['ct']==='Undetermined','Unknown Ct changed.');
auditCheck($project['rows'][2]['ct_state']==='unknown' && $project['rows'][2]['ct']==='','Empty Ct changed.');
echo "CSV blank lines, original precision and missing/undetermined Ct: passed\n";
$invalid=SmartlabQpcrImport::preview("Well,Sample,Target,Ct\nA01,C01,TARGET_A,-2\nA02,C02,TARGET_A,999\n", 'SIM-invalid.csv');
$project=SmartlabQpcrImport::project($invalid,$invalid['suggested']);
auditCheck($project['rows'][0]['ct_state']==='needs_review' && $project['rows'][1]['ct_state']==='needs_review','Implausible numeric Ct was silently accepted.');
foreach (array("Well,Sample,Target,Ct\nZ99,C01,TARGET_A,25\n", "Well,Sample,Target,Ct\nA1,C01,TARGET_A,25\nA01,C02,TARGET_A,26\n") as $badCsv) {
    $bad=SmartlabQpcrImport::preview($badCsv,'SIM-invalid.csv'); $rejected=false;
    try { SmartlabQpcrImport::project($bad,$bad['suggested']); } catch (\Elabftw\Exceptions\AppException) { $rejected=true; }
    auditCheck($rejected,'Invalid/duplicate well was silently accepted.');
}
$workbook='/elabftw/uploads/7e/7e4f881d-ae21-4bde-a650-55600b0acb67.xlsx';
if (is_file($workbook)) {
    $bytes=file_get_contents($workbook); $readme=SmartlabQpcrImport::preview($bytes,'SIM-results.xlsx');
    auditCheck($readme['sheets']===array('README','Ct_Results') && $readme['rows']===array(),'Workbook sheet selector lost the non-table first sheet.');
    $ctSheet=SmartlabQpcrImport::preview($bytes,'SIM-results.xlsx','Ct_Results');
    auditCheck(count($ctSheet['rows'])===96 && $ctSheet['suggested']['ct']===3,'XLSX result sheet was not parsed.');
}
echo "XLSX sheet relationships/selection and invalid Ct/well handling: passed\n";
echo "Database rollback: passed\n";
