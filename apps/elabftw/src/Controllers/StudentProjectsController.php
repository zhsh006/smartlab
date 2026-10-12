<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\CreateUploadFromLocalFile;
use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Enums\BasePermissions;
use Elabftw\Models\Experiments;
use Elabftw\Exceptions\ResourceNotFoundException;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\SmartlabProjects;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use function array_merge;
use function file_exists;

final class StudentProjectsController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canOperateExperiments($this->app->Users, $portalView)) {
            $this->app->Session->getFlashBag()->add('ko', '我的课题仅用于学生端查看个人参与的课题进展。');
            return new RedirectResponse('/dashboard.php');
        }

        if ($this->app->Request->isMethod('POST')
            && $this->app->Request->request->has('smartlab_action')) {
            $action = trim($this->app->Request->request->getString('smartlab_action'));
            $Projects = new SmartlabProjects($this->app->Users);
            if ($action === 'create_project') {
                $code = trim($this->app->Request->request->getString('code'));
                $name = trim($this->app->Request->request->getString('name'));
                $description = trim($this->app->Request->request->getString('description'));

                if ($code !== '' && $name !== '') {
                    $projectId = $Projects->createForCurrentUser($code, $name, $description);
                    $this->app->Session->getFlashBag()->add('ok', '个人课题已创建。');
                    return new RedirectResponse('/student-projects.php?id=' . $projectId);
                }

                $this->app->Session->getFlashBag()->add('ko', '课题编号和名称不能为空。');
            }

            if ($action === 'create_task') {
                $projectId = $this->app->Request->request->getInt('project_id');
                $title = trim($this->app->Request->request->getString('task_title'));
                $description = trim($this->app->Request->request->getString('task_description'));
                $dueDate = trim($this->app->Request->request->getString('task_due_date'));
                $priority = trim($this->app->Request->request->getString('task_priority'));
                if ($projectId > 0 && $title !== '') {
                    $Projects->createTaskForCurrentUser($projectId, $title, $description, $dueDate !== '' ? $dueDate : null, $priority);
                    $this->app->Session->getFlashBag()->add('ok', '任务已添加。');
                    return new RedirectResponse('/student-projects.php?id=' . $projectId);
                }
                $this->app->Session->getFlashBag()->add('ko', '任务标题不能为空。');
            }

            if ($action === 'update_task_status') {
                $projectId = $this->app->Request->request->getInt('project_id');
                $taskId = $this->app->Request->request->getInt('task_id');
                $status = trim($this->app->Request->request->getString('task_status'));
                if ($projectId > 0 && $taskId > 0) {
                    $Projects->updateTaskStatusForCurrentUser($projectId, $taskId, $status);
                    $this->app->Session->getFlashBag()->add('ok', '任务状态已更新。');
                    return new RedirectResponse('/student-projects.php?id=' . $projectId);
                }
            }

            if ($action === 'create_report') {
                $projectId = $this->app->Request->request->getInt('project_id');
                $reportType = trim($this->app->Request->request->getString('report_type'));
                $title = trim($this->app->Request->request->getString('report_title'));
                $progressSummary = trim($this->app->Request->request->getString('progress_summary'));
                $blockers = trim($this->app->Request->request->getString('blockers'));
                $nextPlan = trim($this->app->Request->request->getString('next_plan'));
                if ($projectId > 0 && $title !== '' && $progressSummary !== '') {
                    $Projects->createReportForCurrentUser($projectId, $reportType, $title, $progressSummary, $blockers, $nextPlan);
                    $this->app->Session->getFlashBag()->add('ok', '汇报已提交。');
                    return new RedirectResponse('/student-projects.php?id=' . $projectId);
                }
                $this->app->Session->getFlashBag()->add('ko', '汇报标题和进展内容不能为空。');
            }

            if ($action === 'create_deliverable') {
                $projectId = $this->app->Request->request->getInt('project_id');
                $deliverableType = trim($this->app->Request->request->getString('deliverable_type'));
                $title = trim($this->app->Request->request->getString('deliverable_title'));
                $linkUrl = trim($this->app->Request->request->getString('link_url'));
                $note = trim($this->app->Request->request->getString('deliverable_note'));
                if ($projectId > 0 && $title !== '') {
                    $Projects->createDeliverableForCurrentUser($projectId, $deliverableType, $title, $linkUrl, $note);
                    $this->app->Session->getFlashBag()->add('ok', '阶段成果已归档。');
                    return new RedirectResponse('/student-projects.php?id=' . $projectId);
                }
                $this->app->Session->getFlashBag()->add('ko', '阶段成果标题不能为空。');
            }

            if ($action === 'create_result_experiment') {
                $projectId = $this->app->Request->request->getInt('project_id');
                if ($projectId > 0) {
                    $Projects->readOneForCurrentUser($projectId);
                    $experimentId = $this->createResultExperiment($projectId);
                    $this->app->Session->getFlashBag()->add('ok', '实验记录已创建，并已上传结果图片和数据。');
                    return new RedirectResponse('/experiments.php?mode=view&id=' . $experimentId);
                }
            }

            return new RedirectResponse('/student-projects.php');
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'student-projects.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '我的课题';
    }

    #[Override]
    protected function getData(): array
    {
        $projectId = $this->app->Request->query->getInt('id');
        $Projects = new SmartlabProjects($this->app->Users);
        $projectsArr = $Projects->readForCurrentUser();
        $activeProject = null;
        $projectSamplesArr = array();
        $projectExperimentsArr = array();
        $projectReviewsArr = array();
        $projectActivityArr = array();
        $projectMembersArr = array();
        $projectTasksArr = array();
        $projectReportsArr = array();
        $projectDeliverablesArr = array();

        if ($projectId === 0 && !empty($projectsArr)) {
            $projectId = (int) $projectsArr[0]['id'];
        }

        if ($projectId > 0) {
            try {
                $activeProject = $Projects->readOneForCurrentUser($projectId);
                $projectSamplesArr = $Projects->readSamples($projectId);
                $projectExperimentsArr = $Projects->readExperiments($projectId);
                $projectReviewsArr = $Projects->readReviewFlows($projectId);
                $projectActivityArr = $Projects->readRecentActivity($projectId);
                $projectMembersArr = $Projects->readMembers($projectId);
                $projectTasksArr = $Projects->readTasksForCurrentUser($projectId);
                $projectReportsArr = $Projects->readReports($projectId);
                $projectDeliverablesArr = $Projects->readDeliverables($projectId);
            } catch (ResourceNotFoundException) {
                $this->app->Session->getFlashBag()->add('ko', '当前账号未参与该课题，或课题不存在。');
            }
        }

        return array_merge(parent::getData(), array(
            'projectsArr' => $projectsArr,
            'activeProject' => $activeProject,
            'projectSamplesArr' => $projectSamplesArr,
            'projectExperimentsArr' => $projectExperimentsArr,
            'projectReviewsArr' => $projectReviewsArr,
            'projectActivityArr' => $projectActivityArr,
            'projectMembersArr' => $projectMembersArr,
            'projectTasksArr' => $projectTasksArr,
            'projectReportsArr' => $projectReportsArr,
            'projectDeliverablesArr' => $projectDeliverablesArr,
        ));
    }

    private function createResultExperiment(int $projectId): int
    {
        $title = '学生端综合验证实验 · qPCR/WB/IF 结果';
        $body = '<h2>实验目的</h2><p>验证学生端从课题发起实验、上传结果、生成汇报材料并提交导师审核的闭环流程。</p>'
            . '<h2>实验记录</h2><p>本记录包含仪器导出结果、显微图、Western blot 图和原始统计表，用于完成实验识别、断点分析、跳步识别和导师审核。</p>'
            . '<h2>初步结论</h2><p>qPCR、Western blot 与免疫荧光结果均已作为附件归档，可在项目与审核区勾选关键附件后提交导师端。</p>';

        $Experiments = new Experiments($this->app->Users);
        $experimentId = $Experiments->create(
            title: $title,
            body: $body,
            canreadBase: BasePermissions::Team,
            canwriteBase: BasePermissions::User,
        );

        $SmartlabExperiments = new SmartlabExperiments($this->app->Users);
        $SmartlabExperiments->linkProject($experimentId, $projectId);

        $Experiment = new Experiments($this->app->Users, $experimentId);
        $demoDir = '/tmp/smartlab-demo-results';
        $files = array(
            'qpcr_amplification_curves.png' => 'qPCR 扩增曲线图。',
            'qpcr_fold_change_bar.png' => 'qPCR fold change 柱状图。',
            'qpcr_raw_ct_and_fold_change.csv' => 'qPCR Ct 与 fold change 数据。',
            'western_blot_bands.png' => 'Western blot 条带图。',
            'western_blot_intensity.csv' => 'Western blot 灰度定量表。',
            'if_microscopy_merge.png' => '免疫荧光合并图。',
            'if_cell_count_summary.csv' => '免疫荧光细胞计数汇总表。',
        );

        foreach ($files as $filename => $comment) {
            $path = $demoDir . '/' . $filename;
            if (!file_exists($path)) {
                continue;
            }
            $Experiment->Uploads->create(new CreateUploadFromLocalFile($filename, $path, $comment));
        }

        return $experimentId;
    }
}
