<?php
/**
 * 由 SmartLab 模型的构造函数幂等创建“运行期数据表”。
 * 首次部署时执行一次，保证所有页面/流程所需的表都存在（页面本身也会懒创建，但部署不应依赖用户先访问某页）。
 *   docker cp tools/warmup-runtime-tables.php smartlab-workbench-web:/elabftw/cache/
 *   docker exec smartlab-workbench-web php /elabftw/cache/warmup-runtime-tables.php
 */
require '/elabftw/vendor/autoload.php';

use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabDataCenter;
use Elabftw\Models\SmartlabInventory;
use Elabftw\Models\SmartlabPeriodReports;
use Elabftw\Models\SmartlabPrimerDesign;
use Elabftw\Models\SmartlabProjects;
use Elabftw\Models\Users\Users;

$users = new Users(1, 1);

new SmartlabBenchRecords($users);   // smartlab_bench_records
new SmartlabProjects($users);       // smartlab_project_tasks / reports / deliverables / experiment_report_materials
new SmartlabPeriodReports($users);  // smartlab_period_reports
new SmartlabDataCenter($users);     // smartlab_data_packages / smartlab_agents
new SmartlabInventory($users);      // smartlab_my_consumable_hidden
new SmartlabPrimerDesign($users);   // smartlab_primer_design_records

echo "runtime tables ensured\n";
