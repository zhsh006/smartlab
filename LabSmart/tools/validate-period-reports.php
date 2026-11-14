<?php
declare(strict_types=1);
// Focused permission and freeze regression. Fixtures and feedback are rolled back.
chdir('/elabftw'); require 'vendor/autoload.php';
use Elabftw\Elabftw\Db;
use Elabftw\Models\Users\Users;
use Elabftw\Models\SmartlabPeriodReports;
use Elabftw\Exceptions\AppException;
function reportCheck(bool $ok,string $why):void { if(!$ok){throw new RuntimeException($why);} }
function reportDenied(callable $action,string $why):void { $denied=false;try{$action();}catch(AppException){$denied=true;}reportCheck($denied,$why); }
$db=Db::getConnection();
$users=$db->q('SELECT u.userid,u.email,ut.teams_id FROM users u JOIN users2teams ut ON ut.users_id=u.userid WHERE u.email IN ("preview.student@local.test","smartlab.sysadmin@local.test")')->fetchAll();
$byMail=array_column($users,null,'email');
$s=$byMail['preview.student@local.test'];$m=$byMail['smartlab.sysadmin@local.test'];
$student=new SmartlabPeriodReports(new Users((int)$s['userid'],(int)$s['teams_id']));
$mentor=new SmartlabPeriodReports(new Users((int)$m['userid'],(int)$m['teams_id']));
$upload=(int)$db->q('SELECT u.id FROM uploads u JOIN experiments e ON e.id=u.item_id WHERE e.userid='.(int)$s['userid'].' AND u.real_name="SIM_Figure_IF_v1.png" AND u.type="experiments" AND u.state=1 ORDER BY u.id DESC LIMIT 1')->fetchColumn();
reportCheck($upload>0,'An uploaded audit figure is required.');
$input=['period_start'=>'2026-10-02','period_end'=>'2026-10-15','title'=>'[事务模拟验收] report','findings'=>'SIMULATED ONLY','figure_ids'=>[$upload]];
$count=(int)$db->q('SELECT COUNT(*) FROM smartlab_period_reports')->fetchColumn();
$feedbackCount=(int)$db->q('SELECT COUNT(*) FROM smartlab_period_report_feedback')->fetchColumn();
$db->q('START TRANSACTION');
try {
    $id=$student->save($input);
    reportDenied(fn()=>$mentor->readOne($id),'Mentor could read an unsubmitted draft.');
    $student->submit($id);
    $read=$mentor->readOne($id);
    reportCheck($read['can_feedback'] && !$read['is_owner'],'Project mentor could not read submitted report.');
    reportDenied(fn()=>$mentor->save($input,$id),'Mentor could modify student report.');
    reportDenied(fn()=>$mentor->submit($id),'Mentor could submit student report.');
    reportDenied(fn()=>$student->save($input,$id),'Owner could modify frozen report.');
    reportDenied(fn()=>$student->addFeedback($id,'fake mentor'),'Student could impersonate mentor feedback.');
    reportDenied(fn()=>$mentor->addFeedback($id,'  '),'Empty feedback accepted.');
    $frozen=json_encode($student->readOne($id)['figures']);
    $mentor->addFeedback($id,'[模拟验收] 请复核原图曝光与未检出值。');
    reportCheck(count($student->readFeedback($id))===1,'Student did not see feedback.');
    reportCheck(json_encode($student->readOne($id)['figures'])===$frozen,'Feedback changed frozen figures.');
    $db->q('SAVEPOINT report_role');
    $db->q('UPDATE smartlab_project_members SET role="学生" WHERE user_id='.(int)$m['userid']);
    reportDenied(fn()=>$mentor->readOne($id),'Non-mentor project member could read the report.');
    reportDenied(fn()=>$mentor->addFeedback($id,'forbidden'),'Role revocation did not stop feedback.');
    $db->q('ROLLBACK TO SAVEPOINT report_role');
    $personal=$input;$personal['figure_ids']=[];
    $personalId=$student->save($personal);$student->submit($personalId);
    reportDenied(fn()=>$mentor->readOne($personalId),'Report without project context was shared by guessing.');
    $invalid=$input;$invalid['period_start']='2026-02-31';
    reportDenied(fn()=>$student->save($invalid),'Impossible calendar date accepted.');
    echo "Draft privacy / project mentor scope / ownership / frozen content / feedback / role revocation / calendar dates: passed\n";
} finally { $db->q('ROLLBACK'); }
reportCheck((int)$db->q('SELECT COUNT(*) FROM smartlab_period_reports')->fetchColumn()===$count,'A regression report remained.');
reportCheck((int)$db->q('SELECT COUNT(*) FROM smartlab_period_report_feedback')->fetchColumn()===$feedbackCount,'Regression feedback remained.');
echo "Rollback: passed\n";
