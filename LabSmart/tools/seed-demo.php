<?php
/**
 * SmartLab demo seed: project + weekly tasks + material lots (idempotent).
 * Run inside the app container:
 *   docker cp tools/seed-demo.php smartlab-workbench-web:/elabftw/cache/seed-demo.php
 *   docker exec smartlab-workbench-web php /elabftw/cache/seed-demo.php
 */
require '/elabftw/vendor/autoload.php';

use Elabftw\Elabftw\Db;

$Db = Db::getConnection();
$team = 1;
$mentor = 1;
$student = 2;

// 确保运行期表存在（模型构造函数幂等创建，等价于 tools/warmup-runtime-tables.php）
$warmUsers = new \Elabftw\Models\Users\Users(1, 1);
new \Elabftw\Models\SmartlabBenchRecords($warmUsers);
new \Elabftw\Models\SmartlabProjects($warmUsers);
new \Elabftw\Models\SmartlabPeriodReports($warmUsers);
new \Elabftw\Models\SmartlabDataCenter($warmUsers);

function q(Db $Db, string $sql, array $params = []): PDOStatement
{
    $req = $Db->prepare($sql);
    foreach ($params as $key => $value) {
        $req->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
    }
    $Db->execute($req);
    return $req;
}

/* 1) project */
$row = q($Db, 'SELECT id FROM smartlab_projects WHERE team_id = :t AND code = :c', ['t' => $team, 'c' => 'HPC-2026'])->fetch();
if ($row === false) {
    q($Db, 'INSERT INTO smartlab_projects (team_id, code, name, description, created_by) VALUES (:t, :c, :n, :d, :u)', [
        't' => $team, 'c' => 'HPC-2026', 'n' => '海马抑制性环路研究',
        'd' => '研究 5xFAD 小鼠海马抑制性环路（GAD67 / PV）变化及 PCN 干预效果', 'u' => $mentor,
    ]);
    $projectId = (int) $Db->lastInsertId();
    echo "project created id=$projectId\n";
} else {
    $projectId = (int) $row['id'];
    echo "project exists id=$projectId\n";
}
q($Db, 'INSERT IGNORE INTO smartlab_project_members (project_id, user_id, role) VALUES (:p, :u, :r)', ['p' => $projectId, 'u' => $mentor, 'r' => '负责人']);
q($Db, 'INSERT IGNORE INTO smartlab_project_members (project_id, user_id, role) VALUES (:p, :u, :r)', ['p' => $projectId, 'u' => $student, 'r' => '学生']);
foreach ([1, 2, 3] as $experimentId) {
    q($Db, 'INSERT IGNORE INTO smartlab_project_entities (project_id, entity_type, entity_id) VALUES (:p, "experiment", :e)', ['p' => $projectId, 'e' => $experimentId]);
}

/* 2) weekly plan tasks */
$tasks = [
    ['完成 GAD67 WB 重复实验', 'done', '2026-10-15', 'high', '2026-10-15 09:30:00'],
    ['订购 NeuN 抗体', 'done', '2026-10-17', 'normal', '2026-10-15 15:10:00'],
    ['qPCR 引物效率验证', 'todo', '2026-10-18', 'high', null],
    ['整理第 3 周汇报 Figure', 'todo', '2026-10-19', 'normal', null],
    ['IF 切片重复（PV / NeuN）', 'doing', '2026-10-20', 'normal', null],
    ['分析行为学数据', 'todo', '2026-10-21', 'low', null],
];
foreach ($tasks as [$title, $status, $due, $priority, $completed]) {
    $exists = q($Db, 'SELECT id FROM smartlab_project_tasks WHERE project_id = :p AND title = :t', ['p' => $projectId, 't' => $title])->fetch();
    if ($exists === false) {
        q($Db, 'INSERT INTO smartlab_project_tasks (project_id, title, status, priority, due_date, assigned_userid, created_by, completed_at)
            VALUES (:p, :t, :s, :pr, :due, :a, :c, :done)', [
            'p' => $projectId, 't' => $title, 's' => $status, 'pr' => $priority, 'due' => $due,
            'a' => $student, 'c' => $mentor, 'done' => $completed,
        ]);
    }
}

/* 3) materials + lots (for the reminders panel) */
function ensureMaterial(Db $Db, int $team, int $user, string $code, string $name, string $category): int
{
    $row = q($Db, 'SELECT id FROM smartlab_materials WHERE team_id = :t AND code = :c', ['t' => $team, 'c' => $code])->fetch();
    if ($row !== false) {
        return (int) $row['id'];
    }
    q($Db, 'INSERT INTO smartlab_materials (team_id, code, name, category, unit, created_by) VALUES (:t, :c, :n, :cat, :u, :by)', [
        't' => $team, 'c' => $code, 'n' => $name, 'cat' => $category, 'u' => '瓶', 'by' => $user,
    ]);
    return (int) $Db->lastInsertId();
}

function ensureLot(Db $Db, int $materialId, string $lotNo, string $expiresAt, string $location): void
{
    $exists = q($Db, 'SELECT id FROM smartlab_material_lots WHERE material_id = :m AND lot_no = :l', ['m' => $materialId, 'l' => $lotNo])->fetch();
    if ($exists === false) {
        q($Db, 'INSERT INTO smartlab_material_lots (material_id, lot_no, expires_at, location_label, note) VALUES (:m, :l, :e, :loc, :n)', [
            'm' => $materialId, 'l' => $lotNo, 'e' => $expiresAt, 'loc' => $location, 'n' => '演示数据',
        ]);
    }
}

$in3 = date('Y-m-d', time() + 3 * 86400);
$in10 = date('Y-m-d', time() + 10 * 86400);
$in40 = date('Y-m-d', time() + 40 * 86400);
ensureLot($Db, ensureMaterial($Db, $team, $mentor, 'RE-ECL', 'ECL 发光液（超敏）', '试剂'), 'L2026-09', $in3, '4℃ 冰箱 · 2 层');
ensureLot($Db, ensureMaterial($Db, $team, $mentor, 'AB-GAD67', 'Anti-GAD67（Abcam ab26116）', '抗体'), 'GR3399-2', $in10, '−20℃ 冰箱 · 抗体盒 A');
ensureLot($Db, ensureMaterial($Db, $team, $mentor, 'RE-BSA', 'BSA 组分 V', '试剂'), 'B22-08', $in40, '室温货架');
echo "seed done: project + tasks + materials\n";
