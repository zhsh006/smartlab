<?php
declare(strict_types=1);
// Integration acceptance: requires seeded students, a project, and upload metadata.
// Creates only transaction fixtures; verifies rollback before successful completion.
// Usage: docker exec --user nginx CONTAINER php validate-bench-workflow.php /elabftw
$appRoot = rtrim($argv[1] ?? '/elabftw', '/');
$changes = rtrim($argv[2] ?? $appRoot, '/') . '/';
// A filesystem probe works even when the optional PHP POSIX extension is absent.
$cacheProbe = tempnam($appRoot . '/cache', 'bench-owner-check-');
if ($cacheProbe === false) { throw new RuntimeException('Application cache is not writable; repair its owner before acceptance.'); }
try { $sameCacheOwner = fileowner($cacheProbe) === fileowner($appRoot . '/cache'); }
finally { unlink($cacheProbe); }
if (!$sameCacheOwner) {
    throw new RuntimeException('Run application checks as the cache owner: docker exec --user nginx CONTAINER php validate-bench-workflow.php /elabftw');
}
chdir($appRoot); require $appRoot . '/vendor/autoload.php';
require $changes.'src/Models/SmartlabBenchRecords.php';
require $changes.'src/Models/SmartlabExperiments.php';
require $changes.'src/Models/SmartlabReviews.php';
$parser=new class {use \Elabftw\Traits\TwigTrait; public function twig(): \Twig\Environment{return $this->getTwig(true);}};
$twig=$parser->twig();
$twig->setLoader(new \Twig\Loader\FilesystemLoader(array($changes.'src/templates',$appRoot.'/src/templates')));
foreach(glob($changes.'src/templates/*.html') as $path){$twig->parse($twig->tokenize(new \Twig\Source(file_get_contents($path),basename($path))));}
echo "Twig syntax: passed\n";
$db=\Elabftw\Elabftw\Db::getConnection();
$candidate=$db->q('SELECT pm.user_id,p.id AS project_id,p.team_id FROM smartlab_project_members pm JOIN smartlab_projects p ON p.id=pm.project_id WHERE pm.role="学生" ORDER BY p.id,pm.user_id LIMIT 1')->fetch();
if (!$candidate) { throw new \RuntimeException('A seeded student project is required for acceptance.'); }
$student=new \Elabftw\Models\Users\Users((int)$candidate['user_id'],(int)$candidate['team_id']);
$bench=new \Elabftw\Models\SmartlabBenchRecords($student);
$assay=new \Elabftw\Models\SmartlabExperiments($student);
new \Elabftw\Models\SmartlabReviews($student);
function expectFailure(callable $test):void{try{$test();}catch(\Elabftw\Exceptions\AppException $e){return;}throw new \RuntimeException('Expected rejection did not occur');}
$db->q('START TRANSACTION');
try{
 $experiment=new \Elabftw\Models\Experiments($student);
 $id=$experiment->create(title:'[事务验收] 实验现场记录',body:'');
 $assay->initializeAssayPack($id,'western_blot');
 $assay->linkProject($id,(int)$candidate['project_id']);
 $linked=$assay->readProjects($id);
 if ((int)$linked[0]['id'] !== (int)$candidate['project_id'] || (int)$linked[0]['project_id'] !== (int)$candidate['project_id']) { throw new \RuntimeException('Linked project dropdown/authorization ID differs'); }
 $record=$bench->save($id,array('record_key'=>'plan','record_state'=>'confirmed','objective'=>'验证现场记录与版本审核','expected_revision'=>0));
 expectFailure(fn()=>$bench->save($id,array('record_key'=>'plan','record_state'=>'confirmed','objective'=>'冲突输入','expected_revision'=>0)));
 expectFailure(fn()=>$bench->save($id,array('record_key'=>'step.prepare','record_state'=>'confirmed','expected_revision'=>0)));
 expectFailure(fn()=>$bench->save($id,array('record_key'=>'step.prepare','record_state'=>'draft','evidence_ids'=>[2147483647],'expected_revision'=>0)));
 foreach($bench->read($id)['steps'] as $step){
  $input=array('record_key'=>'step.'.$step['definition']['key'],'record_state'=>'confirmed','expected_revision'=>0);
  foreach($step['definition']['fields'] as $field){if($field['required'])$input[$field['key']]='验收实际条件';}
  $bench->save($id,$input);
 }
 // Clone only metadata into an isolated fixture; no file is read or written.
 $source=$db->q('SELECT * FROM uploads LIMIT 1')->fetch();unset($source['id']);
 $source['item_id']=$id;$source['type']='experiments';$source['userid']=$student->userid;$source['real_name']='验收结果.csv';$source['long_name']='bench-fixture-'.$id.'.csv';$source['state']=1;
 $columns=array_keys($source);$insert=$db->prepare('INSERT INTO uploads (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_map(fn($c)=>':'.$c,$columns)).')');
 foreach($source as $column=>$value){$insert->bindValue(':'.$column,$value,$value===null?\PDO::PARAM_NULL:(is_int($value)?\PDO::PARAM_INT:\PDO::PARAM_STR));}$db->execute($insert);$uploadId=(int)$db->lastInsertId();
 $prior=$bench->read($id)['records']['step.prepare'];
 expectFailure(fn()=>$bench->save($id,array('record_key'=>'step.prepare','record_state'=>'confirmed','sample_label'=>'验收','groups'=>'组A','entry_mode'=>'paper','occurred_at'=>'2026-10-11T10:00','entry_reason'=>'纸单转录','expected_revision'=>(int)$prior['revision'])));
 $paper=$bench->save($id,array('record_key'=>'step.prepare','record_state'=>'confirmed','sample_label'=>'验收','groups'=>'组A','entry_mode'=>'paper','occurred_at'=>'2026-10-11T10:00','entry_reason'=>'纸单转录','evidence_ids'=>[$uploadId],'expected_revision'=>(int)$prior['revision']));
 if($paper['occurred_at']!=='2026-10-11 02:00:00'){throw new \RuntimeException('Operation timezone conversion failed');}
 $bench->save($id,array('record_key'=>'result','record_state'=>'confirmed','summary'=>'验收观测值','conclusion'=>'验收完成','evidence_ids'=>[$uploadId],'result_rows'=>[['sample'=>'A1','group'=>'A','indicator'=>'信号','value'=>'0','unit'=>'a.u.']],'expected_revision'=>0));
 if($bench->read($id)['missing']){throw new \RuntimeException('Completed record incorrectly blocked');}
 $assay->submitReview($id,(int)$candidate['project_id'],'请检查统一记录');
 $flow=$db->q('SELECT id FROM smartlab_review_flows WHERE entity_type="experiment" AND entity_id='.$id.' ORDER BY id DESC LIMIT 1')->fetchColumn();
 $bench->recordSubmission($id,(int)$flow,'formal','请检查统一记录');
 if ($bench->read($id)['changed_keys'] !== []) { throw new \RuntimeException('Unchanged empty body or attachment metadata was incorrectly flagged'); }
 $bench->save($id,array('record_key'=>'result','record_state'=>'confirmed','summary'=>'提交后补充','conclusion'=>'修订结论','evidence_ids'=>[$uploadId],'expected_revision'=>1));
 $snapshot=$bench->read($id,(int)$flow);
 if($snapshot['result']['data']['fields']['conclusion']!=='验收完成'||!$snapshot['changed_keys']){throw new \RuntimeException('Submission snapshot changed with live record');}
 if($snapshot['result']['data']['rows'][0]['value']!=='0'){throw new \RuntimeException('Zero result changed');}
 $task=$db->q('SELECT assignee_userid FROM smartlab_review_tasks WHERE flow_id='.(int)$flow.' ORDER BY id DESC LIMIT 1')->fetch();
 $mentor=new \Elabftw\Models\Users\Users((int)$task['assignee_userid'],(int)$candidate['team_id']);
 (new \Elabftw\Models\SmartlabReviews($mentor))->applyDecision((int)$flow,'request_changes','补充本步原始证据。');
 if(!isset($bench->read($id)['records']['feedback.'.$flow])){throw new \RuntimeException('Mentor feedback was not journaled');}
 $outsiderRow=$db->q('SELECT userid FROM users WHERE email="student@labsmart.box"')->fetch();
 $outsider=new \Elabftw\Models\Users\Users((int)$outsiderRow['userid'],(int)$candidate['team_id']);
 expectFailure(fn()=>(new \Elabftw\Models\SmartlabBenchRecords($outsider))->save($id,['record_key'=>'plan','objective'=>'越权输入','expected_revision'=>1]));
 $html=$twig->render('bench-notebook.html',array('bench'=>$snapshot,'Entity'=>new \Elabftw\Models\Experiments($student,$id),'smartlabCanManageExperiments'=>false));
 if(!str_contains($html,'验收完成')||str_contains($html,'<form')){throw new \RuntimeException('Readonly snapshot rendering failed');}
 $pdf=$twig->render('bench-pdf.html',array('bench'=>$snapshot));if(!str_contains($pdf,'纸质转录'))throw new \RuntimeException('Paper operation sheet rendering failed');
 echo "Permissions, conflict rejection, paper evidence/time, zero values, immutable submission and Twig rendering: passed\n";
}finally{$db->q('ROLLBACK');}
if((int)$db->q('SELECT COUNT(*) FROM experiments WHERE id='.$id)->fetchColumn()!==0){throw new \RuntimeException('Fixture remained after rollback');}
echo "Rollback verified: no test experiment remains.\n";
