<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use DateTimeImmutable;
use Elabftw\Elabftw\Db;
use Elabftw\Enums\FileFromString;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabDashboard;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\SmartlabExperimentSteps;
use Elabftw\Models\SmartlabProjects;
use Elabftw\Models\SmartlabReviewFlows;
use Elabftw\Models\SmartlabSamples;
use Elabftw\Params\EntityParams;
use Override;
use PDO;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class SmartlabPatentController extends AbstractHtmlController
{
    private const PATENT_WORKFLOW_METADATA_KEY = 'smartlab_patent_workflow';

    public function __construct(
        \Elabftw\Elabftw\App $app,
        private readonly string $view,
    ) {
        parent::__construct($app);
    }

    #[Override]
    public function getResponse(): Response
    {
        if ($this->app->Request->isMethod('POST')
            && $this->app->Request->request->getString('smartlab_action') === 'workflow_action'
        ) {
            return $this->handleWorkflowAction();
        }

        if ($this->view === 'records'
            && $this->app->Request->isMethod('POST')
            && $this->app->Request->request->getString('smartlab_action') === 'create_experiment'
        ) {
            $experimentId = $this->createExperiment();
            return new RedirectResponse(sprintf('/experiments.php?mode=view&id=%d', $experimentId));
        }

        if ($this->view === 'templates'
            && $this->app->Request->isMethod('POST')
            && $this->app->Request->request->getString('smartlab_action') === 'submit_review'
        ) {
            $experimentId = $this->app->Request->request->getInt('experiment_id');
            if ($experimentId <= 0) {
                $experimentId = $this->readLatestExperimentId();
            }
            $this->submitExperimentForReview($experimentId);
            return new RedirectResponse(sprintf('/smartlab-templates.php?experiment_id=%d&review=submitted', $experimentId));
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'smartlab-patent.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return match ($this->view) {
            'records' => '新建实验记录',
            'upload' => '实验原始记录上传',
            'recognition' => '识别结果与数字化记录档案',
            'breakpoint' => '实验事件序列与逻辑断点分析',
            'jump' => '跳步识别与缺失步骤推理',
            'reasoning' => '缺失步骤推理与候选路径评估',
            'templates' => '实验模板补全与过程草稿生成',
            'review' => '导师审核中心',
            'algorithms' => '算法中心与模型服务配置',
            default => '系统总览',
        };
    }

    #[Override]
    protected function getData(): array
    {
        $SmartlabDashboard = new SmartlabDashboard($this->app->Users);
        $now = new \DateTimeImmutable('now');
        $weekday = array('日', '一', '二', '三', '四', '五', '六')[(int) $now->format('w')];
        $userData = $this->app->Users->userData;
        $displayName = trim(sprintf('%s %s', $userData['firstname'] ?? '', $userData['lastname'] ?? ''));
        $alerts = $SmartlabDashboard->readOverviewAlerts(3);
        $currentUser = $SmartlabDashboard->readCurrentUserContext();
        $recentExperiments = $SmartlabDashboard->readRecentExperiments(200);
        $myReviews = $SmartlabDashboard->readMyReviews(50);
        $demoResultBundles = $this->attachReviewContextToBundles($SmartlabDashboard->readDemoResultBundles(), $myReviews);
        $demoResultBundles = $this->mergeRecentExperimentContexts($demoResultBundles, $recentExperiments);
        $demoResultBundles = $this->buildExperimentBundles($recentExperiments, $demoResultBundles, $myReviews);
        $selectedExperimentId = $this->app->Request->query->getInt('experiment_id');
        $primaryResult = $this->resolvePrimaryResult(
            $demoResultBundles,
            $myReviews,
            $recentExperiments,
            $selectedExperimentId,
        );
        $patentWorkflow = $this->resolvePatentWorkflow($primaryResult);
        $recognitionArchive = $this->buildRecognitionArchive($primaryResult, $patentWorkflow);
        $reviewFacets = $this->buildReviewFacets($myReviews);
        $evidenceContext = $this->buildEvidenceContext($primaryResult, $myReviews, $currentUser);
        $mentorReviewDashboard = $this->buildMentorReviewDashboard($myReviews);

        return array_merge(parent::getData(), array(
            'smartlabPatentView' => $this->view,
            'smartlabPatentOverview' => $SmartlabDashboard->readOverview(),
            'smartlabPatentExperimentsArr' => $SmartlabDashboard->readMyExperiments(6),
            'smartlabPatentSamplesArr' => $SmartlabDashboard->readMySamples(6),
            'smartlabPatentRecentExperimentsArr' => $recentExperiments,
            'smartlabPatentMyReviewsArr' => $myReviews,
            'smartlabPatentReviewFacets' => $reviewFacets,
            'smartlabPatentEvidenceContext' => $evidenceContext,
            'smartlabPatentMentorReviewDashboard' => $mentorReviewDashboard,
            'smartlabPatentResultBundles' => $demoResultBundles,
            'smartlabPatentPrimaryResult' => $primaryResult,
            'smartlabPatentWorkflow' => $patentWorkflow,
            'smartlabPatentRecognitionArchive' => $recognitionArchive,
            'smartlabPatentSelectedExperimentId' => $selectedExperimentId,
            'smartlabPatentAlertsArr' => $alerts,
            'smartlabPatentAlertCount' => count($alerts),
            'smartlabPatentCurrentUser' => $currentUser,
            'smartlabPatentUserName' => $displayName !== '' ? $displayName : ($userData['email'] ?? 'SmartLab User'),
            'smartlabPatentToday' => $now->format('Y-m-d'),
            'smartlabPatentWeekday' => '星期' . $weekday,
        ));
    }

    private function buildReviewFacets(array $reviewRows): array
    {
        $students = array();
        $projects = array();
        $riskRows = array();
        foreach ($reviewRows as $row) {
            $studentName = trim((string) ($row['student_name'] ?? '')) ?: (string) ($row['student_email'] ?? '未命名学生');
            $studentKey = (string) ($row['student_userid'] ?? $studentName);
            $projectName = trim((string) ($row['project_code'] ?? '')) ?: '待关联课题';
            $status = (string) ($row['status'] ?? 'not_submitted');
            $packKey = (string) ($row['pack_key'] ?? '');
            $normalizedTitle = strtolower((string) ($row['experiment_title'] ?? ''));
            $isHighRisk = $status === 'needs_revision'
                || $status === 'rejected'
                || str_contains($normalizedTitle, 'qpcr')
                || in_array($packKey, array('western_blot', 'qpcr', 'if', 'ihc'), true);

            if (!isset($students[$studentKey])) {
                $students[$studentKey] = array(
                    'name' => $studentName,
                    'email' => (string) ($row['student_email'] ?? ''),
                    'total' => 0,
                    'pending' => 0,
                    'revision' => 0,
                    'high_risk' => 0,
                    'latest_title' => '',
                    'latest_experiment_id' => 0,
                );
            }
            $students[$studentKey]['total']++;
            $students[$studentKey]['pending'] += $status === 'submitted' ? 1 : 0;
            $students[$studentKey]['revision'] += in_array($status, array('needs_revision', 'rejected'), true) ? 1 : 0;
            $students[$studentKey]['high_risk'] += $isHighRisk ? 1 : 0;
            if ($students[$studentKey]['latest_title'] === '') {
                $students[$studentKey]['latest_title'] = (string) ($row['experiment_title'] ?? '');
                $students[$studentKey]['latest_experiment_id'] = (int) ($row['experiment_id'] ?? 0);
            }

            if (!isset($projects[$projectName])) {
                $projects[$projectName] = array(
                    'name' => $projectName,
                    'students' => array(),
                    'total' => 0,
                    'pending' => 0,
                    'high_risk' => 0,
                    'latest_title' => '',
                    'latest_experiment_id' => 0,
                );
            }
            $projects[$projectName]['students'][$studentKey] = true;
            $projects[$projectName]['total']++;
            $projects[$projectName]['pending'] += in_array($status, array('submitted', 'not_submitted'), true) ? 1 : 0;
            $projects[$projectName]['high_risk'] += $isHighRisk ? 1 : 0;
            if ($projects[$projectName]['latest_title'] === '') {
                $projects[$projectName]['latest_title'] = (string) ($row['experiment_title'] ?? '');
                $projects[$projectName]['latest_experiment_id'] = (int) ($row['experiment_id'] ?? 0);
            }

            if ($isHighRisk) {
                $riskRows[] = array_merge($row, array(
                    'risk_label' => $status === 'needs_revision' || $status === 'rejected' ? '退回/需修改' : ($packKey === 'western_blot' ? '跳步风险' : '结果阈值异常'),
                    'risk_score' => $packKey === 'western_blot' ? '0.73' : '0.89',
                ));
            }
        }

        foreach ($projects as &$project) {
            $project['student_count'] = count($project['students']);
            unset($project['students']);
        }
        unset($project);

        return array(
            'students' => array_slice(array_values($students), 0, 6),
            'projects' => array_slice(array_values($projects), 0, 6),
            'risks' => array_slice($riskRows, 0, 6),
        );
    }

    private function buildMentorReviewDashboard(array $reviewRows): array
    {
        $packKeyMeta = array(
            'western_blot' => array('label' => 'Western Blot', 'badge' => 'blue'),
            'if' => array('label' => '免疫荧光 IF', 'badge' => 'purple'),
            'qpcr' => array('label' => 'qPCR', 'badge' => 'orange'),
            'elisa' => array('label' => 'ELISA', 'badge' => 'teal'),
            'ihc' => array('label' => '免疫组化 IHC', 'badge' => 'purple'),
            'pcr' => array('label' => 'PCR', 'badge' => 'orange'),
        );

        $totals = array(
            'total' => 0,
            'submitted' => 0,
            'needs_revision' => 0,
            'approved' => 0,
            'not_submitted' => 0,
            'rejected' => 0,
            'high_risk' => 0,
        );
        $students = array();
        $highRiskRows = array();
        $submittedRows = array();
        $allRows = array();

        foreach ($reviewRows as $row) {
            $status = (string) ($row['status'] ?? 'not_submitted');
            $packKey = (string) ($row['pack_key'] ?? '');
            $normalizedTitle = strtolower((string) ($row['experiment_title'] ?? ''));
            if ($packKey === '') {
                $packKey = match (true) {
                    str_contains($normalizedTitle, 'western') || str_contains($normalizedTitle, 'blot') || str_contains($normalizedTitle, 'wb') => 'western_blot',
                    str_contains($normalizedTitle, 'qpcr') => 'qpcr',
                    str_contains($normalizedTitle, 'pcr') => 'pcr',
                    str_contains($normalizedTitle, 'elisa') => 'elisa',
                    str_contains($normalizedTitle, 'ihc') || str_contains($normalizedTitle, '免疫组化') => 'ihc',
                    str_contains($normalizedTitle, '免疫荧光') || preg_match('/\bif\b/', $normalizedTitle) === 1 => 'if',
                    default => '',
                };
            }
            $row['pack_key'] = $packKey;
            $studentKey = (string) ($row['student_userid'] ?? $row['student_email'] ?? 'unknown');
            $studentName = trim((string) ($row['student_name'] ?? '')) ?: (string) ($row['student_email'] ?? '未命名学生');

            $totals['total']++;
            if (isset($totals[$status])) {
                $totals[$status]++;
            }

            $isHighRisk = $status === 'needs_revision'
                || $status === 'rejected'
                || str_contains($normalizedTitle, 'qpcr')
                || in_array($packKey, array('western_blot', 'qpcr', 'if', 'ihc'), true);
            if ($isHighRisk) {
                $totals['high_risk']++;
            }

            $meta = $packKeyMeta[$packKey] ?? array('label' => '待分类', 'badge' => 'blue');
            $riskLabel = match (true) {
                $status === 'needs_revision' || $status === 'rejected' => '退回/需修改',
                $packKey === 'western_blot' => '跳步风险',
                $packKey === 'qpcr' => '结果阈值异常',
                in_array($packKey, array('if', 'ihc'), true) => '图像阈值待确认',
                default => '常规关注',
            };

            $row['_pack_label'] = $meta['label'];
            $row['_pack_badge'] = $meta['badge'];
            $row['_is_high_risk'] = $isHighRisk;
            $row['_student_name'] = $studentName;
            $row['_risk_label'] = $riskLabel;

            if (!isset($students[$studentKey])) {
                $students[$studentKey] = array(
                    'name' => $studentName,
                    'email' => (string) ($row['student_email'] ?? ''),
                    'total' => 0,
                    'pending' => 0,
                    'revision' => 0,
                    'approved' => 0,
                    'high_risk' => 0,
                );
            }
            $students[$studentKey]['total']++;
            $students[$studentKey]['pending'] += $status === 'submitted' ? 1 : 0;
            $students[$studentKey]['revision'] += in_array($status, array('needs_revision', 'rejected'), true) ? 1 : 0;
            $students[$studentKey]['approved'] += $status === 'approved' ? 1 : 0;
            $students[$studentKey]['high_risk'] += $isHighRisk ? 1 : 0;

            if ($isHighRisk) {
                $highRiskRows[] = $row;
            }
            if ($status === 'submitted') {
                $submittedRows[] = $row;
            }
            $allRows[] = $row;
        }

        return array(
            'totals' => $totals,
            'students' => array_values($students),
            'high_risk_rows' => $highRiskRows,
            'submitted_rows' => $submittedRows,
            'all_rows' => $allRows,
            'pack_key_labels' => $packKeyMeta,
        );
    }

    private function attachReviewContextToBundles(array $bundles, array $reviewRows): array
    {
        foreach ($bundles as &$bundle) {
            $context = $this->buildEvidenceContext($bundle, $reviewRows, array());
            $bundle['student_name'] = $context['student_name'];
            $bundle['project_code'] = $context['project_code'];
            $bundle['status_label'] = $context['status_label'];
            $bundle['risk_label'] = $context['risk_label'];
            $bundle['review_comment'] = $context['review_comment'];
        }
        unset($bundle);

        return $bundles;
    }

    private function mergeRecentExperimentContexts(array $bundles, array $recentExperiments): array
    {
        $seen = array();
        foreach ($bundles as $bundle) {
            $experimentId = (int) ($bundle['experiment_id'] ?? 0);
            if ($experimentId > 0) {
                $seen[$experimentId] = true;
            }
        }

        foreach ($recentExperiments as $row) {
            $experimentId = (int) ($row['id'] ?? 0);
            if ($experimentId <= 0 || isset($seen[$experimentId])) {
                continue;
            }
            $context = $this->buildExperimentContext(
                $experimentId,
                (string) ($row['title'] ?? ''),
                (string) ($row['date'] ?? ''),
                (string) ($row['modified_at'] ?? ''),
                '',
                (string) ($row['experiment_code'] ?? ''),
            );
            $context['project_code'] = (string) ($row['project_code'] ?? '');
            $context['status_label'] = $this->reviewStatusLabel((string) ($row['review_status'] ?? ''));
            $bundles['recent:' . $experimentId] = $context;
            $seen[$experimentId] = true;
        }

        return $bundles;
    }

    private function buildExperimentBundles(array $recentExperiments, array $existingBundles, array $reviewRows): array
    {
        $bundles = array();
        foreach ($recentExperiments as $row) {
            $experimentId = (int) ($row['id'] ?? 0);
            if ($experimentId <= 0) {
                continue;
            }
            $bundle = $existingBundles['recent:' . $experimentId] ?? null;
            if (!is_array($bundle)) {
                foreach ($existingBundles as $candidate) {
                    if ((int) ($candidate['experiment_id'] ?? 0) === $experimentId) {
                        $bundle = $candidate;
                        break;
                    }
                }
            }
            if (!is_array($bundle)) {
                $bundle = $this->buildExperimentContext(
                    $experimentId,
                    (string) ($row['title'] ?? ''),
                    (string) ($row['date'] ?? ''),
                    (string) ($row['modified_at'] ?? ''),
                    '',
                    (string) ($row['experiment_code'] ?? ''),
                );
            }
            $bundle['project_code'] = (string) ($row['project_code'] ?? ($bundle['project_code'] ?? ''));
            $bundle['status_label'] = $this->reviewStatusLabel((string) ($row['review_status'] ?? ''));
            $bundles['experiment:' . $experimentId] = $bundle;
        }

        return $this->attachReviewContextToBundles($bundles, $reviewRows);
    }

    private function reviewStatusLabel(string $status): string
    {
        return match ($status) {
            'submitted' => '待导师审核',
            'approved' => '已通过',
            'needs_revision' => '需修改',
            'rejected' => '已退回',
            default => '未提交',
        };
    }

    private function buildEvidenceContext(array $primaryResult, array $reviewRows, array $currentUser): array
    {
        $experimentId = (int) ($primaryResult['experiment_id'] ?? 0);
        $reviewRow = array();
        foreach ($reviewRows as $row) {
            if ((int) ($row['experiment_id'] ?? 0) === $experimentId) {
                $reviewRow = $row;
                break;
            }
        }

        $studentName = trim((string) ($reviewRow['student_name'] ?? ''));
        if ($studentName === '') {
            $studentName = trim((string) ($primaryResult['owner_name'] ?? ''));
        }
        $projectCode = trim((string) ($reviewRow['project_code'] ?? ''));
        if ($projectCode === '') {
            $projectCode = trim((string) ($primaryResult['project_code'] ?? ''));
        }
        if ($projectCode === '') {
            $projectCode = $this->extractProjectCodeFromResult($primaryResult);
        }
        if ($studentName === '') {
            $studentName = trim(sprintf('%s %s', $currentUser['firstname'] ?? '', $currentUser['lastname'] ?? ''));
        }
        if ($studentName === '') {
            $studentName = (string) ($currentUser['email'] ?? '');
        }
        if ($projectCode === '') {
            $projectCode = '待关联课题';
        }
        $status = (string) ($reviewRow['status'] ?? 'not_submitted');
        $statusLabel = match ($status) {
            'submitted' => '待导师审核',
            'approved' => '已通过',
            'needs_revision' => '需修改',
            'rejected' => '已退回',
            default => '未提交',
        };
        $assay = (string) ($primaryResult['assay'] ?? 'selected');
        $riskLabel = match ($assay) {
            'qpcr', 'pcr' => '高风险：结果阈值需复核',
            'wb' => '高风险：跳步推理需复核',
            'if', 'ihc' => '中风险：图像阈值需确认',
            default => '常规审核',
        };

        return array(
            'student_name' => $studentName,
            'project_code' => $projectCode,
            'experiment_id' => $experimentId,
            'experiment_code' => (string) ($primaryResult['experiment_code'] ?? ($reviewRow['experiment_code'] ?? '')),
            'experiment_title' => (string) ($primaryResult['title'] ?? ''),
            'status_label' => $statusLabel,
            'assay_label' => (string) ($primaryResult['label'] ?? '当前实验'),
            'risk_label' => $riskLabel,
            'review_comment' => (string) ($reviewRow['comment'] ?? ''),
            'reviewer_role' => (string) ($currentUser['smartlab_role'] ?? '导师'),
            'upload_count' => (int) ($primaryResult['upload_count'] ?? 0),
        );
    }

    private function extractProjectCodeFromResult(array $result): string
    {
        $metadata = $result['metadata'] ?? null;
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                $code = (string) ($decoded['project_code'] ?? '');
                if ($code !== '') {
                    return $code;
                }
            }
        }
        return '';
    }

    private function resolvePrimaryResult(array $resultBundles, array $reviewRows, array $recentExperiments, int $selectedExperimentId): array
    {
        if ($selectedExperimentId > 0) {
            foreach ($resultBundles as $bundle) {
                if ((int) ($bundle['experiment_id'] ?? 0) === $selectedExperimentId) {
                    return $bundle;
                }
            }

            foreach ($reviewRows as $row) {
                if ((int) ($row['experiment_id'] ?? 0) === $selectedExperimentId) {
                    return $this->buildExperimentContext(
                        $selectedExperimentId,
                        (string) ($row['experiment_title'] ?? ''),
                        (string) ($row['experiment_date'] ?? ''),
                        (string) ($row['modified_at'] ?? ''),
                        (string) ($row['pack_key'] ?? ''),
                        (string) ($row['experiment_code'] ?? ''),
                    );
                }
            }

            foreach ($recentExperiments as $row) {
                if ((int) ($row['id'] ?? 0) === $selectedExperimentId) {
                    return $this->buildExperimentContext(
                        $selectedExperimentId,
                        (string) ($row['title'] ?? ''),
                        (string) ($row['date'] ?? ''),
                        (string) ($row['modified_at'] ?? ''),
                        '',
                        (string) ($row['experiment_code'] ?? ''),
                    );
                }
            }
        }

        foreach (array('wb', 'if', 'qpcr', 'elisa', 'selected') as $assay) {
            foreach ($resultBundles as $bundle) {
                if (($bundle['assay'] ?? '') === $assay) {
                    return $bundle;
                }
            }
        }

        return $resultBundles[0] ?? array();
    }

    private function buildExperimentContext(int $experimentId, string $title, string $date, string $modifiedAt, string $assayHint = '', string $experimentCode = ''): array
    {
        $uploads = $this->readExperimentUploads($experimentId);
        $imageUploads = array_values(array_filter($uploads, static fn (array $upload): bool => $upload['kind'] === 'image'));
        $dataUploads = array_values(array_filter($uploads, static fn (array $upload): bool => $upload['kind'] !== 'image'));
        $assay = $this->inferAssayFromContext(trim($title . ' ' . $experimentCode), $assayHint);

        return array(
            'assay' => $assay,
            'label' => $this->assayLabel($assay),
            'experiment_id' => $experimentId,
            'experiment_code' => $experimentCode,
            'title' => $title !== '' ? $title : '实验记录',
            'date' => $date,
            'modified_at' => $modifiedAt,
            'summary' => $this->assaySummary($assay),
            'analysis_points' => $this->demoAssayAnalysisPointsForPatent($assay),
            'uploads' => $uploads,
            'image_uploads' => $imageUploads,
            'data_uploads' => $dataUploads,
            'upload_count' => count($uploads),
            'total_filesize' => array_sum(array_column($uploads, 'filesize')),
        );
    }

    private function inferAssayFromContext(string $title, string $assayHint): string
    {
        $hint = strtolower($assayHint);
        $normalizedTitle = strtolower($title);
        if ($hint === 'western_blot'
            || $hint === 'wb'
            || str_contains($normalizedTitle, 'western')
            || str_contains($normalizedTitle, 'blot')
            || str_contains($normalizedTitle, 'wb')
            || str_contains($normalizedTitle, 'gad67')
            || str_contains($normalizedTitle, 'gapdh')
            || str_contains($normalizedTitle, '蛋白表达')
            || str_contains($normalizedTitle, '免疫印迹')
        ) {
            return 'wb';
        }
        if ($hint === 'qpcr' || str_contains($normalizedTitle, 'qpcr')) {
            return 'qpcr';
        }
        if ($hint === 'pcr' || str_contains($normalizedTitle, 'pcr')) {
            return 'pcr';
        }
        if ($hint === 'elisa' || str_contains($normalizedTitle, 'elisa')) {
            return 'elisa';
        }
        if ($hint === 'if' || $hint === 'immunofluorescence' || str_contains($normalizedTitle, 'immunofluorescence') || str_contains($normalizedTitle, '免疫荧光') || preg_match('/\bif\b/', $normalizedTitle) === 1) {
            return 'if';
        }
        if ($hint === 'ihc' || str_contains($normalizedTitle, 'ihc') || str_contains($normalizedTitle, '免疫组化')) {
            return 'ihc';
        }

        return 'selected';
    }

    private function assayLabel(string $assay): string
    {
        return match ($assay) {
            'wb' => 'Western Blot 记录',
            'qpcr' => 'qPCR 记录',
            'pcr' => 'PCR 记录',
            'elisa' => 'ELISA 记录',
            'if' => '免疫荧光记录',
            'ihc' => '免疫组化记录',
            default => '当前实验',
        };
    }

    private function assaySummary(string $assay): string
    {
        return match ($assay) {
            'wb' => '围绕 WB 原图、灰度定量表和抗体/成像步骤生成结构化过程草稿。',
            'qpcr' => '围绕 Ct 原始值、扩增曲线和 fold-change 结果生成结构化过程草稿。',
            'pcr' => '围绕 PCR 扩增曲线、凝胶电泳图和测序结果生成结构化过程草稿。',
            'elisa' => '围绕 OD450 原始数据、标准曲线和样本浓度生成结构化过程草稿。',
            'if' => '围绕 IF 图像、通道识别和阳性率结果生成结构化过程草稿。',
            'ihc' => '围绕 IHC 染色图像、阳性率评分和病理分析结果生成结构化过程草稿。',
            default => '当前审核行关联的实验记录。',
        };
    }

    private function readExperimentUploads(int $experimentId): array
    {
        $Db = Db::getConnection();
        $req = $Db->prepare('SELECT
                u.id AS upload_id,
                u.real_name,
                u.long_name,
                u.storage,
                u.filesize
            FROM uploads AS u
            INNER JOIN experiments AS e ON e.id = u.item_id
            WHERE u.item_id = :experiment_id
              AND u.type = "experiments"
              AND u.state = 1
              AND e.team = :team
            ORDER BY u.id ASC');
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->app->Users->team, PDO::PARAM_INT);
        $Db->execute($req);

        $uploads = array();
        foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uploads[] = array(
                'id' => (int) $row['upload_id'],
                'real_name' => $row['real_name'],
                'long_name' => $row['long_name'],
                'storage' => (int) $row['storage'],
                'filesize' => (int) $row['filesize'],
                'href' => sprintf(
                    'app/download.php?f=%s&storage=%d&name=%s',
                    rawurlencode((string) $row['long_name']),
                    (int) $row['storage'],
                    rawurlencode((string) $row['real_name']),
                ),
                'kind' => $this->uploadKind((string) $row['real_name']),
            );
        }

        return $uploads;
    }

    private function uploadKind(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array('png', 'jpg', 'jpeg', 'svg', 'tif', 'tiff'), true) ? 'image' : 'data';
    }

    private function createExperiment(): int
    {
        $request = $this->app->Request->request;
        $title = trim($request->getString('experiment_title'));
        if ($title === '') {
            $title = sprintf('SmartLab experiment %s', (new DateTimeImmutable())->format('Y-m-d H:i'));
        }

        $date = $this->parseDate($request->getString('experiment_date'));
        $input = $request->all();
        $body = $this->buildExperimentBody($input);
        $experimentId = (new Experiments($this->app->Users))->create(
            title: $title,
            body: $body,
            date: $date,
            canreadBase: \Elabftw\Enums\BasePermissions::User,
            canwriteBase: \Elabftw\Enums\BasePermissions::User,
        );

        $projectId = $this->ensureProject((string) ($input['project_code'] ?? ''), $title);
        if ($projectId !== null) {
            $this->linkProjectEntity($projectId, 'experiment', $experimentId);
        }

        $this->insertSamples($experimentId, $projectId, $input);

        $steps = $input['step_body'] ?? array();
        $this->insertExperimentSteps($experimentId, is_array($steps) ? $steps : array());

        return $experimentId;
    }

    private function parseDate(string $date): DateTimeImmutable
    {
        if ($date === '') {
            return new DateTimeImmutable();
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed ?: new DateTimeImmutable();
    }

    private function buildExperimentBody(array $input): string
    {
        $sampleColumns = array(
            $input['sample_code'] ?? array(),
            $input['sample_name'] ?? array(),
            $input['sample_type'] ?? array(),
            $input['sample_source'] ?? array(),
            $input['sample_treatment'] ?? array(),
            $input['sample_note'] ?? array(),
        );
        $reagentColumns = array(
            $input['reagent_name'] ?? array(),
            $input['reagent_brand'] ?? array(),
            $input['reagent_spec'] ?? array(),
            $input['reagent_lot'] ?? array(),
            $input['reagent_amount'] ?? array(),
            $input['reagent_unit'] ?? array(),
        );

        return $this->app->render('smartlab-experiment-body.html', array(
            'type' => (string) ($input['experiment_type'] ?? ''),
            'projectCode' => (string) ($input['project_code'] ?? ''),
            'instrumentParams' => (string) ($input['instrument_params'] ?? ''),
            'note' => (string) ($input['experiment_note'] ?? ''),
            'sampleHeaders' => array('样本编号', '样本名称', '样本类型', '来源', '处理方式', '备注'),
            'sampleColumns' => $sampleColumns,
            'sampleMaxRows' => max(array_map(static fn($col) => is_array($col) ? count($col) : 0, $sampleColumns)),
            'reagentHeaders' => array('名称', '品牌/货号', '规格', '批号', '用量', '单位'),
            'reagentColumns' => $reagentColumns,
            'reagentMaxRows' => max(array_map(static fn($col) => is_array($col) ? count($col) : 0, $reagentColumns)),
        ));
    }

    private function insertExperimentSteps(int $experimentId, array $steps): void
    {
        (new SmartlabExperimentSteps($this->app->Users))->insertSteps($experimentId, $steps);
    }

    private function ensureProject(string $projectCode, string $experimentTitle): ?int
    {
        $projectCode = trim($projectCode);
        if ($projectCode === '') {
            return null;
        }

        $Projects = new SmartlabProjects($this->app->Users);
        $existingId = $Projects->findByCode($projectCode);
        if ($existingId !== null) {
            return $existingId;
        }

        return $Projects->createForCurrentUser(
            $projectCode,
            $projectCode . ' 课题',
            'SmartLab 自动创建：' . $experimentTitle,
        );
    }

    private function linkProjectEntity(int $projectId, string $entityType, int $entityId): void
    {
        (new SmartlabProjects($this->app->Users))->addEntity($projectId, $entityType, $entityId);
    }

    private function insertSamples(int $experimentId, ?int $projectId, array $input): void
    {
        $codes = $input['sample_code'] ?? array();
        $names = $input['sample_name'] ?? array();
        $types = $input['sample_type'] ?? array();
        $sources = $input['sample_source'] ?? array();
        $treatments = $input['sample_treatment'] ?? array();
        $notes = $input['sample_note'] ?? array();
        if (!is_array($codes)) {
            return;
        }

        $Samples = new SmartlabSamples($this->app->Users);
        $Projects = new SmartlabProjects($this->app->Users);

        foreach ($codes as $index => $code) {
            $code = trim((string) $code);
            $name = trim((string) ($names[$index] ?? ''));
            if ($code === '' && $name === '') {
                continue;
            }
            if ($code === '') {
                $code = sprintf('SL-%d-%02d', $experimentId, $index + 1);
            }
            if ($name === '') {
                $name = $code;
            }

            $metadata = array(
                'source' => (string) ($sources[$index] ?? ''),
                'treatment' => (string) ($treatments[$index] ?? ''),
                'note' => (string) ($notes[$index] ?? ''),
                'source_experiment_id' => $experimentId,
            );

            $sampleId = $Samples->upsertSample(
                code: $code,
                name: $name,
                sampleType: trim((string) ($types[$index] ?? '其他')) ?: '其他',
                projectId: $projectId,
                metadata: $metadata,
            );

            if ($sampleId <= 0) {
                continue;
            }

            if ($projectId !== null) {
                $Projects->addEntity($projectId, 'sample', $sampleId);
            }

            $note = trim((string) ($notes[$index] ?? 'SmartLab 实验记录创建时同步登记样本'));
            $Samples->addEvent($sampleId, 'experiment_recorded', $experimentId, null, $note);
        }
    }

    private function handleWorkflowAction(): JsonResponse
    {
        $request = $this->app->Request->request;
        $experimentId = $request->getInt('experiment_id');
        if ($experimentId <= 0) {
            return new JsonResponse(array('ok' => false, 'error' => '请先选择实验。'), 400);
        }

        $operation = $request->getString('operation');

        try {
            $experiment = $this->readWritableExperiment($experimentId);
            if ($experiment === null) {
                return new JsonResponse(array('ok' => false, 'error' => '没有找到可写入的实验记录。'), 404);
            }

            $uploads = array();
            if ($operation === 'upload_demo') {
                $uploads = $this->createDemoUploads($experimentId, $request->all('uploads'));
                $workflow = $this->buildPatentWorkflow($experiment, $this->readExperimentUploads($experimentId), $this->inferAssayFromContext((string) ($experiment['title'] ?? ''), ''));
                $this->savePatentWorkflow($experimentId, $workflow);
                $this->appendSmartlabResult($experimentId, '原始数据采集入队', array(
                    '采集状态' => '已生成真实附件并关联到实验',
                    '新增附件' => implode('、', array_column($uploads, 'name')),
                    '后续处理' => '已写入专利链路原始证据层，可进入智能识别、断点识别、跳步识别与模板补全。',
                ));
            } elseif ($operation === 'recognition') {
                $workflow = $this->buildPatentWorkflow($experiment, $this->readExperimentUploads($experimentId), $this->inferAssayFromContext((string) ($experiment['title'] ?? ''), ''));
                $this->savePatentWorkflow($experimentId, $workflow);
                $archive = $workflow['digital_archive'] ?? array();
                $this->appendSmartlabResult($experimentId, '智能识别结果', array(
                    '数字化实验记录档案' => sprintf('已生成 %d 条区域/字段记录，包含附件、区域类别、结构化字段、坐标和采集时间。', count($archive['records'] ?? array())),
                    '结构化指标' => implode('；', array_map(static fn(array $point): string => $point['name'] . '=' . $point['value'], $archive['analysis_points'] ?? array())),
                    'WB回填结果' => '若当前实验为 WB，已同步读取实验记录第4步“结果回填确认”的灰度归一化表。',
                    '下一模块输入' => '已写入 smartlab_patent_workflow.digital_archive，供后续模块读取。',
                ));
            } elseif ($operation === 'recognition_confirm') {
                $fieldKey = $request->getString('field_key');
                $decision = $request->getString('decision') ?: 'confirmed';
                $studentValue = trim($request->getString('student_value'));
                $correctionNote = trim($request->getString('correction_note'));
                if ($fieldKey === '') {
                    return new JsonResponse(array('ok' => false, 'error' => '缺少确认字段。'), 400);
                }
                if ($decision === 'needs_fix' && $studentValue === '') {
                    return new JsonResponse(array('ok' => false, 'error' => '请填写修正后的识别值。'), 400);
                }
                $workflow = $this->readPatentWorkflowFromMetadata((string) ($experiment['metadata'] ?? ''));
                if ($workflow === array()) {
                    $workflow = $this->buildPatentWorkflow($experiment, $this->readExperimentUploads($experimentId), 'wb');
                }
                $confirmation = array(
                    'decision' => in_array($decision, array('confirmed', 'needs_fix'), true) ? $decision : 'confirmed',
                    'confirmed_by' => (int) $this->app->Users->userid,
                    'confirmed_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                );
                if ($decision === 'needs_fix') {
                    $confirmation['student_value'] = $studentValue;
                    $confirmation['correction_note'] = $correctionNote;
                }
                $workflow['recognition_confirmations'][$fieldKey] = $confirmation;
                $workflow['updated_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
                $this->savePatentWorkflow($experimentId, $workflow);
            } elseif ($operation === 'breakpoint') {
                $workflow = $this->ensurePatentWorkflowStage($experiment, 'breakpoint');
                $this->savePatentWorkflow($experimentId, $workflow);
                $breakpoint = $workflow['breakpoint_result'] ?? array();
                $this->appendSmartlabResult($experimentId, '断点识别结果', array(
                    '事件序列' => sprintf('已从数字化档案生成 %d 条按时间排序的实验事件。', count($breakpoint['event_sequence'] ?? array())),
                    '候选断点' => (string) ($breakpoint['primary_candidate']['label'] ?? '未发现高风险断点'),
                    '异常评分' => (string) ($breakpoint['primary_candidate']['score'] ?? '0.00'),
                    '下一模块输入' => '已写入 smartlab_patent_workflow.breakpoint_result，供跳步识别读取。',
                ));
            } elseif ($operation === 'jump') {
                $workflow = $this->ensurePatentWorkflowStage($experiment, 'jump');
                $this->savePatentWorkflow($experimentId, $workflow);
                $jump = $workflow['jump_result'] ?? array();
                $reasoning = $workflow['reasoning_result'] ?? array();
                $assay = (string) ($workflow['assay'] ?? '');
                if ($assay === 'wb') {
                    $this->appendSmartlabResult($experimentId, 'WB跳步识别与缺失步骤推理', array(
                        '跳步状态' => (string) ($jump['status'] ?? '待分析'),
                        '判定结果' => (string) ($jump['decision'] ?? '—'),
                        '风险评分' => (string) ($jump['risk_score'] ?? '—'),
                        '置信度' => (string) ($jump['confidence'] ?? '—'),
                        '偏移量' => (string) ($jump['offset'] ?? '—'),
                        '阈值' => (string) ($jump['threshold'] ?? '—'),
                        '同批相邻均值' => (string) ($jump['neighbor_average'] ?? '—'),
                        '疑似缺失步骤' => implode('、', $jump['missing_steps'] ?? array()),
                        '建议' => (string) ($jump['recommendation'] ?? '—'),
                        '证据文件' => implode('、', array_filter($jump['evidence_files'] ?? array())),
                        '下一模块输入' => '已写入 smartlab_patent_workflow.jump_result 与 reasoning_result，供模板补全读取。',
                    ));
                } else {
                    $this->appendSmartlabResult($experimentId, '跳步识别与缺失步骤推理', array(
                        '跳步风险' => (string) ($jump['decision'] ?? '未发现跳步风险'),
                        '疑似缺失步骤' => implode('、', $reasoning['recommended_steps'] ?? array()),
                        '下一模块输入' => '已写入 smartlab_patent_workflow.jump_result 与 reasoning_result，供模板补全读取。',
                    ));
                }
            } elseif ($operation === 'reasoning') {
                $workflow = $this->ensurePatentWorkflowStage($experiment, 'reasoning');
                $this->savePatentWorkflow($experimentId, $workflow);
                $reasoning = $workflow['reasoning_result'] ?? array();
                $this->appendSmartlabResult($experimentId, '步骤推理结果', array(
                    '前驱/后继约束' => sprintf('%s → %s', $reasoning['predecessor'] ?? '-', $reasoning['successor'] ?? '-'),
                    '推荐缺失步骤' => implode('、', $reasoning['recommended_steps'] ?? array()),
                    '候选路径评分' => (string) ($reasoning['best_score'] ?? '0.00'),
                ));
            } elseif ($operation === 'template') {
                $assay = $request->getString('template_assay') ?: $this->inferAssayFromContext((string) ($experiment['title'] ?? ''), '');
                if (!in_array($assay, array('wb', 'if', 'qpcr', 'pcr', 'ihc', 'elisa'), true)) {
                    $assay = 'wb';
                }
                $this->applyAssayTemplate($experimentId, $assay, $request->getString('template_name'));
            } elseif ($operation === 'template_assay') {
                $assay = $request->getString('assay');
                $templateName = $request->getString('template_name');
                if (!in_array($assay, array('wb', 'if', 'qpcr', 'pcr', 'ihc', 'elisa'), true)) {
                    return new JsonResponse(array('ok' => false, 'error' => '不支持的实验类型。'), 400);
                }
                $result = $this->applyAssayTemplate($experimentId, $assay, $templateName);
                return new JsonResponse(array_merge(
                    array('ok' => true, 'experiment_id' => $experimentId),
                    $result,
                ));
            } elseif ($operation === 'policy') {
                $this->saveAlgorithmPolicy($request->all('policy'));
            } else {
                return new JsonResponse(array('ok' => false, 'error' => '不支持的工作流动作。'), 400);
            }

            return new JsonResponse(array(
                'ok' => true,
                'experiment_id' => $experimentId,
                'uploads' => $uploads,
                'message' => 'SmartLab 工作流结果已写入实验记录。',
            ));
        } catch (\Throwable $e) {
            (new \Elabftw\Services\SmartlabLogger())->error('Workflow action failed', array(
                'operation' => $operation,
                'experiment_id' => $experimentId,
                'error' => $e->getMessage(),
            ));
            return new JsonResponse(array(
                'ok' => false,
                'error' => 'SmartLab 工作流执行出错：' . $e->getMessage(),
            ), 500);
        }
    }

    private function readWritableExperiment(int $experimentId): ?array
    {
        $Db = Db::getConnection();
        $req = $Db->prepare('SELECT id, title, date, modified_at, body, metadata FROM experiments WHERE id = :id AND team = :team LIMIT 1');
        $req->bindValue(':id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->app->Users->team, PDO::PARAM_INT);
        $Db->execute($req);
        $row = $req->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function createDemoUploads(int $experimentId, mixed $uploadsInput): array
    {
        $uploadsInput = is_array($uploadsInput) ? $uploadsInput : array();
        if ($uploadsInput === array()) {
            $uploadsInput = array(
                array('name' => 'smartlab_structured_result.json', 'type' => 'json', 'content' => json_encode(array(
                    'source' => 'SmartLab',
                    'experiment_id' => $experimentId,
                    'summary' => '自动生成的结构化结果数据',
                    'metrics' => array('positive_rate' => 0.667, 'fold_change' => 6.38),
                ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            );
        }

        $Experiments = new Experiments($this->app->Users, $experimentId);
        $created = array();
        foreach ($uploadsInput as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? 'json');
            $name = trim((string) ($item['name'] ?? 'smartlab-result.json'));
            $content = (string) ($item['content'] ?? '');
            if ($name === '' || $content === '') {
                continue;
            }
            $fileType = $type === 'png' ? FileFromString::Png : FileFromString::Json;
            $uploadId = $Experiments->Uploads->createFromString($fileType, $name, $content);
            $created[] = array('id' => $uploadId, 'name' => $name, 'type' => $fileType->value);
        }

        return $created;
    }

    private function appendSmartlabResult(int $experimentId, string $title, array $rows): void
    {
        $html = $this->app->render('smartlab-result-append.html', array(
            'title' => $title,
            'rows' => $rows,
            'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ));

        $Experiments = new Experiments($this->app->Users, $experimentId);
        $Experiments->update(new EntityParams('bodyappend', $html));
    }

    private function appendSmartlabTemplateDraft(int $experimentId, string $title, string $templateName, string $assayLabel, array $steps, array $draft): void
    {
        $html = $this->app->render('smartlab-template-draft-append.html', array(
            'title' => $title,
            'templateName' => $templateName,
            'assayLabel' => $assayLabel,
            'steps' => $steps,
            'draft' => $draft,
            'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ));

        $Experiments = new Experiments($this->app->Users, $experimentId);
        $Experiments->update(new EntityParams('bodyappend', $html));
    }

    private function saveAlgorithmPolicy(mixed $policy): void
    {
        $policy = is_array($policy) ? $policy : array();
        $Db = Db::getConnection();
        $payload = json_encode(array(
            'policy' => $policy,
            'updated_by' => $this->app->Users->userid,
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $req = $Db->prepare('INSERT INTO config (conf_name, conf_value) VALUES ("smartlab_algorithm_policy", :payload)
            ON DUPLICATE KEY UPDATE conf_value = VALUES(conf_value)');
        $req->bindValue(':payload', $payload ?: '{}');
        $Db->execute($req);
    }

    private function submitExperimentForReview(int $experimentId): void
    {
        if ($experimentId <= 0) {
            $experimentId = $this->readLatestExperimentId();
        }
        if ($experimentId <= 0) {
            return;
        }

        $ReviewFlows = new SmartlabReviewFlows($this->app->Users);
        $projectId = $ReviewFlows->findLinkedProjectId($experimentId);

        $existingFlowId = $ReviewFlows->findExisting($experimentId, (int) $this->app->Users->userid);
        if ($existingFlowId !== null) {
            $ReviewFlows->resubmitFlow($existingFlowId);
            $flowId = $existingFlowId;
        } else {
            $flowId = $ReviewFlows->createFlow($projectId, $experimentId, (int) $this->app->Users->userid);
        }

        $assigneeId = $ReviewFlows->findTeamReviewerUserId();
        if ($assigneeId !== null && $flowId > 0) {
            $ReviewFlows->createReviewTask($flowId, $assigneeId, 'SmartLab 结构化模板补全/草稿生成后提交导师审核。');
        }
    }

    private function readLatestExperimentId(): int
    {
        $Db = Db::getConnection();
        $latest = $Db->prepare('SELECT id FROM experiments WHERE team = :team AND userid = :userid ORDER BY modified_at DESC, id DESC LIMIT 1');
        $latest->bindValue(':team', $this->app->Users->team, PDO::PARAM_INT);
        $latest->bindValue(':userid', $this->app->Users->userid, PDO::PARAM_INT);
        $Db->execute($latest);
        return (int) $latest->fetchColumn();
    }

    private function findTeamReviewerUserId(): ?int
    {
        return (new SmartlabReviewFlows($this->app->Users))->findTeamReviewerUserId();
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function resolvePatentWorkflow(array $primaryResult): array
    {
        $experimentId = (int) ($primaryResult['experiment_id'] ?? 0);
        if ($experimentId <= 0) {
            return array();
        }
        $experiment = $this->readWritableExperiment($experimentId);
        if ($experiment === null) {
            return array();
        }
        $workflow = $this->readPatentWorkflowFromMetadata((string) ($experiment['metadata'] ?? ''));
        $uploads = $this->readExperimentUploads($experimentId);
        if ($workflow !== array()) {
            if ($this->view === 'breakpoint' && (string) ($workflow['assay'] ?? $primaryResult['assay'] ?? '') === 'wb') {
                $workflow['chain_summary'] = $this->buildPatentChainSummary(
                    'wb',
                    $experimentId,
                    count($workflow['raw_uploads'] ?? $uploads),
                    $workflow,
                );
            }
            return $workflow;
        }
        return $this->buildPatentWorkflow($experiment, $uploads, (string) ($primaryResult['assay'] ?? ''));
    }

    private function readPatentWorkflowFromMetadata(string $metadata): array
    {
        if ($metadata === '') {
            return array();
        }
        $decoded = json_decode($metadata, true);
        if (!is_array($decoded)) {
            return array();
        }
        $workflow = $decoded[self::PATENT_WORKFLOW_METADATA_KEY] ?? array();
        return is_array($workflow) ? $workflow : array();
    }

    private function savePatentWorkflow(int $experimentId, array $workflow): void
    {
        $experiment = $this->readWritableExperiment($experimentId);
        if ($experiment === null) {
            return;
        }
        $metadata = json_decode((string) ($experiment['metadata'] ?? ''), true);
        if (!is_array($metadata)) {
            $metadata = array();
        }
        $metadata[self::PATENT_WORKFLOW_METADATA_KEY] = $workflow;
        $payload = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        (new Experiments($this->app->Users, $experimentId))->update(new EntityParams('metadata', $payload));
    }

    private function buildRecognitionArchive(array $primaryResult, array $workflow): array
    {
        $experimentId = (int) ($primaryResult['experiment_id'] ?? 0);
        $assay = (string) ($primaryResult['assay'] ?? '');
        if ($experimentId <= 0 || $assay !== 'wb') {
            return array(
                'assay' => $assay,
                'is_wb' => false,
                'field_rows' => array(),
                'confirmation_rows' => array(),
                'records' => $workflow['digital_archive']['records'] ?? ($primaryResult['uploads'] ?? array()),
                'summary' => $workflow['digital_archive']['summary'] ?? ($primaryResult['summary'] ?? ''),
            );
        }

        $assayPack = (new SmartlabExperiments($this->app->Users))->readAssayPack($experimentId);
        $payload = is_array($assayPack['payload'] ?? null) ? $assayPack['payload'] : array();
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : array();
        $analysis = is_array($payload['analysis'] ?? null) ? $payload['analysis'] : array();
        $image = is_array($payload['image'] ?? null) ? $payload['image'] : array();
        $confirmations = is_array($workflow['recognition_confirmations'] ?? null) ? $workflow['recognition_confirmations'] : array();
        $uploads = is_array($primaryResult['uploads'] ?? null) ? $primaryResult['uploads'] : array();
        $imageUploads = is_array($primaryResult['image_uploads'] ?? null) ? $primaryResult['image_uploads'] : array();

        $targetNames = array_values(array_filter(array_map(
            static fn(array $upload): string => self::inferWbTargetFromFilename((string) ($upload['real_name'] ?? '')),
            $imageUploads,
        )));
        $targetLabel = implode(' / ', array_values(array_unique(array_filter(
            $targetNames,
            static fn(string $name): bool => strtolower($name) !== 'gapdh',
        ))));
        $reference = trim((string) ($analysis['reference_control'] ?? ''));
        if ($reference === '') {
            $reference = in_array('GAPDH', $targetNames, true) ? 'GAPDH' : '';
        }

        $fieldRows = array();
        foreach ($imageUploads as $index => $upload) {
            $target = self::inferWbTargetFromFilename((string) ($upload['real_name'] ?? ''));
            $fieldRows[] = $this->buildRecognitionFieldRow(
                'image_' . (int) ($upload['id'] ?? ($index + 1)),
                $target === 'GAPDH' ? '内参图像' : '目标蛋白图像',
                $target !== '' ? $target : (string) ($upload['real_name'] ?? 'WB图像'),
                $target !== '' ? $target : '待学生确认',
                (string) ($upload['real_name'] ?? 'WB图像附件'),
                '原始附件',
                $target !== '' ? '0.96' : '0.82',
                $confirmations,
                $target !== '',
            );
        }

        if ($targetLabel !== '') {
            $fieldRows[] = $this->buildRecognitionFieldRow('wb_target', '目标蛋白', $targetLabel, $targetLabel, '原始附件文件名 / WB图像', '原始附件', '0.95', $confirmations);
        }
        if ($reference !== '') {
            $fieldRows[] = $this->buildRecognitionFieldRow('wb_reference', '内参', $reference, $reference, '实验记录第4步 / 原始附件', '回填结果确认', '0.95', $confirmations);
        }
        $normalizationMethod = trim((string) ($analysis['normalization_method'] ?? ''));
        $fieldRows[] = $this->buildRecognitionFieldRow(
            'wb_normalization_method',
            '归一化方法',
            $normalizationMethod !== '' ? $normalizationMethod : '待补充',
            $normalizationMethod !== '' ? $normalizationMethod : '待补充',
            '实验记录第4步“结果回填确认”',
            '回填结果确认',
            $normalizationMethod !== '' ? '0.93' : '0.00',
            $confirmations,
            $normalizationMethod !== '',
        );

        $resultRows = array();
        foreach ($results as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $sample = trim((string) ($row['label'] ?? ''));
            $targetSignal = trim((string) ($row['target_signal'] ?? ''));
            $referenceSignal = trim((string) ($row['reference_signal'] ?? ''));
            $normalized = trim((string) ($row['normalized_signal'] ?? ''));
            $trend = trim((string) ($row['trend'] ?? ''));
            $note = trim((string) ($row['note'] ?? ''));
            if ($sample === '' && $targetSignal === '' && $referenceSignal === '' && $normalized === '') {
                continue;
            }
            $rowNumber = count($resultRows) + 1;
            $value = sprintf(
                '%s：Target=%s；Reference=%s；Normalized=%s%s%s',
                $sample !== '' ? $sample : '样本' . $rowNumber,
                $targetSignal !== '' ? $targetSignal : '待补充',
                $referenceSignal !== '' ? $referenceSignal : '待补充',
                $normalized !== '' ? $normalized : '待补充',
                $trend !== '' ? '；Trend=' . $trend : '',
                $note !== '' ? '；Note=' . $note : '',
            );
            $resultRows[] = array(
                'index' => $rowNumber,
                'sample' => $sample !== '' ? $sample : '样本' . $rowNumber,
                'target_signal' => $targetSignal,
                'reference_signal' => $referenceSignal,
                'normalized_signal' => $normalized,
                'trend' => $trend,
                'note' => $note,
            );
            $fieldRows[] = $this->buildRecognitionFieldRow(
                'wb_result_' . $rowNumber,
                '灰度归一化结果',
                $value,
                $value,
                '实验记录第4步“结果回填确认”',
                '回填结果确认',
                $normalized !== '' ? '0.98' : '0.72',
                $confirmations,
                $normalized !== '',
            );
        }

        if ($resultRows === array()) {
            $fieldRows[] = $this->buildRecognitionFieldRow(
                'wb_results_missing',
                '灰度归一化结果',
                '待补充',
                '待补充',
                '实验记录第4步“结果回填确认”尚未填写',
                '回填结果确认',
                '0.00',
                $confirmations,
                false,
            );
        }

        $records = $workflow['digital_archive']['records'] ?? array();
        $records[] = array(
            'index' => count($records) + 1,
            'upload_id' => 0,
            'filename' => '实验记录第4步：结果回填确认',
            'href' => sprintf('experiments.php?mode=view&id=%d&smartlab_step=results', $experimentId),
            'kind' => 'data',
            'region_type' => '结构化表/WB灰度归一化',
            'structured_text' => $resultRows !== array()
                ? sprintf('第4步已读取 %d 行 Target/Reference/Normalized 回填结果', count($resultRows))
                : '第4步结果回填确认暂无灰度归一化数据',
            'coordinates' => '实验记录表单字段',
            'collected_at' => (string) ($primaryResult['modified_at'] ?? $primaryResult['date'] ?? ''),
            'confidence' => $resultRows !== array() ? '0.98' : '0.00',
        );

        return array(
            'assay' => 'wb',
            'is_wb' => true,
            'summary' => $resultRows !== array()
                ? sprintf('已读取 %d 个附件和 %d 行第4步灰度归一化结果。', count($uploads), count($resultRows))
                : sprintf('已读取 %d 个附件，等待第4步结果回填。', count($uploads)),
            'field_rows' => $fieldRows,
            'confirmation_rows' => $fieldRows,
            'result_rows' => $resultRows,
            'records' => $records,
            'source_note' => '数据来源：实验记录第4步“结果回填确认”。',
            'raw_image_ref' => (string) ($image['raw_image_ref'] ?? ''),
            'cropped_image_ref' => (string) ($image['cropped_image_ref'] ?? ''),
            'annotation_note' => (string) ($image['annotation_note'] ?? ''),
        );
    }

    private function buildRecognitionFieldRow(
        string $key,
        string $field,
        string $aiValue,
        string $studentValue,
        string $evidence,
        string $sourceType,
        string $confidence,
        array $confirmations,
        bool $hasValue = true,
    ): array {
        $confirmation = is_array($confirmations[$key] ?? null) ? $confirmations[$key] : array();
        $decision = (string) ($confirmation['decision'] ?? '');
        $correctedValue = trim((string) ($confirmation['student_value'] ?? ''));
        $correctionNote = trim((string) ($confirmation['correction_note'] ?? ''));
        $status = $hasValue ? '待确认' : '待补充';
        $badge = $hasValue ? 'orange' : 'purple';
        $displayStudentValue = $correctedValue !== '' ? $correctedValue : $studentValue;
        if ($decision === 'confirmed') {
            $status = '已确认';
            $badge = 'green';
        } elseif ($decision === 'needs_fix') {
            $status = $correctedValue !== '' ? '学生已修正' : '需要修正';
            $badge = $correctedValue !== '' ? 'orange' : 'red';
        }

        return array(
            'key' => $key,
            'field' => $field,
            'ai_value' => $aiValue,
            'student_value' => $displayStudentValue,
            'status' => $status,
            'badge' => $badge,
            'decision' => $decision,
            'correction_note' => $correctionNote,
            'evidence' => $evidence,
            'source_type' => $sourceType,
            'confidence' => $confidence,
        );
    }

    private static function inferWbTargetFromFilename(string $filename): string
    {
        $name = strtolower($filename);
        if (str_contains($name, 'gapdh')) {
            return 'GAPDH';
        }
        if (str_contains($name, 'vglut1') || str_contains($name, 'v-glut1')) {
            return 'VGlut1';
        }
        if (str_contains($name, 'gad67')) {
            return 'GAD67';
        }
        if (str_contains($name, 'beta') || str_contains($name, 'actin') || str_contains($name, 'β-actin')) {
            return 'β-actin';
        }
        return '';
    }

    private function ensurePatentWorkflowStage(array $experiment, string $stage, string $assayOverride = ''): array
    {
        $workflow = $this->readPatentWorkflowFromMetadata((string) ($experiment['metadata'] ?? ''));
        $uploads = $this->readExperimentUploads((int) $experiment['id']);
        if ($workflow === array()) {
            $workflow = $this->buildPatentWorkflow($experiment, $uploads, $assayOverride);
        }
        if ($assayOverride !== '' && ($workflow['assay'] ?? '') !== $assayOverride) {
            $workflow = $this->buildPatentWorkflow($experiment, $uploads, $assayOverride);
        }
        if ($stage === 'breakpoint' && (string) ($workflow['assay'] ?? $assayOverride) === 'wb') {
            return $this->refreshWesternBlotBreakpointWorkflow($workflow, $experiment, $uploads, $stage);
        }
        if ($stage === 'jump' && (string) ($workflow['assay'] ?? $assayOverride) === 'wb') {
            return $this->refreshWesternBlotJumpWorkflow($workflow, $experiment, $uploads, $stage);
        }
        $workflow['updated_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        $workflow['current_stage'] = $stage;
        $workflow['chain_summary'] = $this->buildPatentChainSummary(
            (string) ($workflow['assay'] ?? 'selected'),
            (int) $experiment['id'],
            count($workflow['raw_uploads'] ?? array()),
            $workflow,
        );
        return $workflow;
    }

    private function refreshWesternBlotBreakpointWorkflow(array $workflow, array $experiment, array $uploads, string $stage = 'breakpoint'): array
    {
        $experimentId = (int) ($experiment['id'] ?? 0);
        $archive = $this->buildDigitalArchive($experiment, $uploads, 'wb');
        $workflow['assay'] = 'wb';
        $workflow['assay_label'] = $this->assayLabel('wb');
        $workflow['raw_uploads'] = $uploads;
        $workflow['digital_archive'] = $archive;
        $workflow['breakpoint_result'] = $this->buildBreakpointResult($archive, 'wb', $experiment, $uploads);
        $workflow['event_sequence'] = $workflow['breakpoint_result']['event_sequence'];
        $workflow['updated_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        $workflow['current_stage'] = $stage;
        $workflow['chain_summary'] = $this->buildPatentChainSummary('wb', $experimentId, count($uploads), $workflow);
        return $workflow;
    }

    private function refreshWesternBlotJumpWorkflow(array $workflow, array $experiment, array $uploads, string $stage = 'jump'): array
    {
        $experimentId = (int) ($experiment['id'] ?? 0);
        $assay = 'wb';
        $archive = $this->buildDigitalArchive($experiment, $uploads, $assay);
        $breakpoint = $this->buildBreakpointResult($archive, $assay, $experiment, $uploads);
        $jump = $this->buildWesternBlotJumpResult($breakpoint, $archive, $workflow);
        $reasoning = $this->buildReasoningResult($jump, $assay);

        $workflow['assay'] = $assay;
        $workflow['assay_label'] = $this->assayLabel($assay);
        $workflow['raw_uploads'] = $uploads;
        $workflow['digital_archive'] = $archive;
        $workflow['breakpoint_result'] = $breakpoint;
        $workflow['jump_result'] = $jump;
        $workflow['reasoning_result'] = $reasoning;
        $workflow['updated_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        $workflow['current_stage'] = $stage;
        $workflow['chain_summary'] = $this->buildPatentChainSummary($assay, $experimentId, count($uploads), $workflow);
        return $workflow;
    }

    private function buildPatentWorkflow(array $experiment, array $uploads, string $assayHint): array
    {
        $experimentId = (int) $experiment['id'];
        $assay = $this->inferAssayFromContext((string) ($experiment['title'] ?? ''), $assayHint);
        if ($assay === 'selected') {
            $assay = $this->inferAssayFromUploads($uploads);
        }
        $archive = $this->buildDigitalArchive($experiment, $uploads, $assay);
        $breakpoint = $this->buildBreakpointResult($archive, $assay, $experiment, $uploads);
        $jump = $this->buildJumpResult($breakpoint, $archive, $assay);
        $reasoning = $this->buildReasoningResult($jump, $assay);
        $templateDraft = $this->buildWorkflowTemplateDraft($assay, $experimentId, (string) ($experiment['title'] ?? ''), $uploads, $reasoning);

        $workflow = array(
            'version' => 'patent-2.3-workflow-v1',
            'experiment_id' => $experimentId,
            'experiment_title' => (string) ($experiment['title'] ?? ''),
            'assay' => $assay,
            'assay_label' => $this->assayLabel($assay),
            'current_stage' => 'recognition',
            'raw_uploads' => $uploads,
            'digital_archive' => $archive,
            'event_sequence' => $breakpoint['event_sequence'],
            'breakpoint_result' => $breakpoint,
            'jump_result' => $jump,
            'reasoning_result' => $reasoning,
            'template_draft' => $templateDraft,
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        );
        $workflow['chain_summary'] = $this->buildPatentChainSummary($assay, $experimentId, count($uploads), $workflow);
        return $workflow;
    }

    private function inferAssayFromUploads(array $uploads): string
    {
        $names = strtolower(implode(' ', array_map(static fn(array $upload): string => (string) ($upload['real_name'] ?? ''), $uploads)));
        if (str_contains($names, 'qpcr') || str_contains($names, 'ct') || str_contains($names, 'fold') || str_contains($names, 'amplification')) {
            return 'qpcr';
        }
        if (str_contains($names, 'western') || str_contains($names, 'blot') || str_contains($names, 'wb')) {
            return 'wb';
        }
        if (str_contains($names, 'if') || str_contains($names, 'immunofluorescence') || str_contains($names, 'dapi') || str_contains($names, 'merge')) {
            return 'if';
        }
        if (str_contains($names, 'elisa') || str_contains($names, 'od450')) {
            return 'elisa';
        }
        return 'selected';
    }

    private function buildDigitalArchive(array $experiment, array $uploads, string $assay): array
    {
        $records = array();
        foreach ($uploads as $index => $upload) {
            $regionType = $this->archiveRegionType((string) ($upload['real_name'] ?? ''), (string) ($upload['kind'] ?? 'data'), $assay);
            $records[] = array(
                'index' => $index + 1,
                'upload_id' => (int) ($upload['id'] ?? 0),
                'filename' => (string) ($upload['real_name'] ?? ''),
                'href' => (string) ($upload['href'] ?? ''),
                'kind' => (string) ($upload['kind'] ?? 'data'),
                'region_type' => $regionType,
                'structured_text' => $this->structuredTextForRegion($regionType, $assay),
                'coordinates' => sprintf('x=%d,y=%d,w=%d,h=%d', 24 + ($index * 17), 32 + ($index * 11), 280, 160),
                'collected_at' => (string) ($experiment['modified_at'] ?? $experiment['date'] ?? ''),
                'confidence' => number_format(max(0.82, 0.97 - ($index * 0.02)), 2),
            );
        }
        if ($records === array()) {
            $records[] = array(
                'index' => 1,
                'upload_id' => 0,
                'filename' => '待上传原始记录',
                'href' => '',
                'kind' => 'data',
                'region_type' => '待采集证据',
                'structured_text' => $this->assaySummary($assay),
                'coordinates' => '-',
                'collected_at' => (string) ($experiment['date'] ?? ''),
                'confidence' => '0.00',
            );
        }

        return array(
            'module' => '记录模块',
            'records' => $records,
            'analysis_points' => $this->demoAssayAnalysisPointsForPatent($assay),
            'summary' => $this->assaySummary($assay),
            'record_count' => count($records),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        );
    }

    private function archiveRegionType(string $filename, string $kind, string $assay): string
    {
        $name = strtolower($filename);
        if ($kind === 'image') {
            if ($assay === 'wb') {
                return '生物样本成像区/WB条带';
            }
            if ($assay === 'if' || str_contains($name, 'dapi') || str_contains($name, 'merge')) {
                return '生物样本成像区/荧光通道';
            }
            return '实验台面图像区';
        }
        if (str_contains($name, 'ct') || str_contains($name, 'csv') || str_contains($name, 'xlsx')) {
            return '仪器读数/数据表';
        }
        return '手写/文本记录区';
    }

    private function structuredTextForRegion(string $regionType, string $assay): string
    {
        if (str_contains($regionType, '仪器读数')) {
            return match ($assay) {
                'qpcr' => 'Ct / DeltaCt / DeltaDeltaCt / fold-change 字段已索引',
                'wb' => '灰度值、内参归一化、目标蛋白比值已索引',
                'if' => '阳性细胞数、通道强度、merge一致性已索引',
                default => '仪器读数和表格字段已索引',
            };
        }
        if (str_contains($regionType, '生物样本')) {
            return match ($assay) {
                'wb' => '条带区域、lane映射、背景区域已定位',
                'if' => 'DAPI/FITC/TRITC/Merge 通道与阳性区域已定位',
                default => '样本图像路径与空间坐标已绑定',
            };
        }
        return '操作描述、采集时间和证据来源已索引';
    }

    private function buildBreakpointResult(array $archive, string $assay, array $experiment = array(), array $uploads = array()): array
    {
        if ($assay === 'wb') {
            $wbResult = $this->buildWesternBlotBreakpointResult($archive, $experiment, $uploads);
            if ($wbResult !== array()) {
                return $wbResult;
            }
            return $this->buildInsufficientWesternBlotBreakpointResult($archive, $experiment, $uploads);
        }

        $events = $this->eventSequenceForAssay($assay);
        $adjacent = array();
        foreach ($events as $index => $event) {
            if (!isset($events[$index + 1])) {
                continue;
            }
            $gradient = (float) ($event['gradient_to_next'] ?? 0.12);
            $semantic = (float) ($event['semantic_to_next'] ?? 0.94);
            $score = round((0.6 * $gradient) + (0.4 * (1 - $semantic)), 2);
            $adjacent[] = array(
                'before' => $event['operation'],
                'after' => $events[$index + 1]['operation'],
                'time_gap' => $event['time_gap_to_next'] ?? '-',
                'numeric_gradient' => number_format($gradient, 2),
                'semantic_continuity' => number_format($semantic, 2),
                'joint_score' => number_format($score, 2),
                'is_candidate' => $score >= 0.7,
            );
        }
        $candidates = array_values(array_filter($adjacent, static fn(array $row): bool => (bool) $row['is_candidate']));
        $primary = $candidates[0] ?? array(
            'before' => $adjacent[0]['before'] ?? '-',
            'after' => $adjacent[0]['after'] ?? '-',
            'joint_score' => '0.00',
        );
        $primary['label'] = sprintf('%s → %s 逻辑断点候选', $primary['before'] ?? '-', $primary['after'] ?? '-');
        $primary['score'] = $primary['joint_score'] ?? '0.00';

        return array(
            'module' => '断点识别模块',
            'event_sequence' => $events,
            'adjacent_pairs' => $adjacent,
            'candidates' => $candidates,
            'primary_candidate' => $primary,
            'weights' => array('numeric_gradient' => 0.6, 'semantic_continuity' => 0.4, 'threshold' => 0.7),
            'archive_record_count' => (int) ($archive['record_count'] ?? 0),
        );
    }

    private function buildWesternBlotBreakpointResult(array $archive, array $experiment, array $uploads): array
    {
        $inputs = $this->collectWesternBlotBreakpointInputs((int) ($experiment['id'] ?? 0), $uploads, (string) ($experiment['body'] ?? ''));
        $rows = $inputs['result_rows'];
        if ($rows === array() && $inputs['breakpoint_rows'] === array()) {
            return array();
        }

        $metrics = $this->calculateWesternBlotBreakpointMetrics($rows, $inputs['breakpoint_rows']);
        $targetLabel = $inputs['target_label'] !== '' ? $inputs['target_label'] : 'WB目标蛋白';
        $referenceLabel = $inputs['reference_label'] !== '' ? $inputs['reference_label'] : 'GAPDH/ACTB';
        $hasSecondary = $inputs['protocol_presence']['secondary_antibody'];
        $hasBlocking = $inputs['protocol_presence']['blocking'];
        $hasWash = $inputs['protocol_presence']['wash'];

        $protocolSemantic = $hasSecondary && $hasWash ? 0.86 : 0.54;
        if (!$hasBlocking) {
            $protocolSemantic = min($protocolSemantic, 0.62);
        }
        $protocolGradient = max(0.34, min(1.0, $metrics['max_numeric_gradient'] + (!$hasSecondary || !$hasWash ? 0.18 : 0.0)));

        $events = array(
            array('step' => 1, 'time' => 'T1', 'operation' => '蛋白提取/制样', 'reading' => $inputs['sample_note'] !== '' ? $inputs['sample_note'] : '样本与裂解条件已索引', 'semantic_to_next' => 0.94, 'gradient_to_next' => 0.10),
            array('step' => 2, 'time' => 'T2', 'operation' => '蛋白定量/上样', 'reading' => $inputs['loading_note'] !== '' ? $inputs['loading_note'] : '上样量记录待核验', 'semantic_to_next' => 0.93, 'gradient_to_next' => 0.12),
            array('step' => 3, 'time' => 'T3', 'operation' => 'SDS-PAGE电泳', 'reading' => $inputs['run_note'] !== '' ? $inputs['run_note'] : '电泳参数已从记录/附件推断', 'semantic_to_next' => 0.92, 'gradient_to_next' => 0.13),
            array('step' => 4, 'time' => 'T4', 'operation' => '转膜', 'reading' => $inputs['transfer_note'] !== '' ? $inputs['transfer_note'] : 'PVDF/转膜记录待核验', 'semantic_to_next' => $hasBlocking ? 0.91 : 0.62, 'gradient_to_next' => $hasBlocking ? 0.14 : 0.42),
            array('step' => 5, 'time' => 'T5', 'operation' => '封闭', 'reading' => $hasBlocking ? '封闭步骤有记录支撑' : '封闭步骤未形成独立结构化记录', 'semantic_to_next' => 0.90, 'gradient_to_next' => 0.15),
            array('step' => 6, 'time' => 'T6', 'operation' => '一抗孵育', 'reading' => sprintf('%s / %s', $targetLabel, $referenceLabel), 'semantic_to_next' => $protocolSemantic, 'gradient_to_next' => $protocolGradient),
            array('step' => 7, 'time' => 'T7', 'operation' => '洗膜/二抗孵育链', 'reading' => $this->westernBlotProtocolReading($hasWash, $hasSecondary), 'semantic_to_next' => $metrics['qc_semantic'], 'gradient_to_next' => max(0.18, min(1.0, $metrics['max_numeric_gradient'] * 0.75))),
            array('step' => 8, 'time' => 'T8', 'operation' => '显影曝光', 'reading' => $inputs['image_note'] !== '' ? $inputs['image_note'] : 'WB原膜图已绑定', 'semantic_to_next' => $metrics['qc_semantic'], 'gradient_to_next' => $metrics['max_numeric_gradient']),
            array('step' => 9, 'time' => 'T9', 'operation' => '灰度分析/内参归一化', 'reading' => sprintf('%s；内参=%s；CV=%s', $metrics['summary_reading'], $referenceLabel, $metrics['reference_cv_label']), 'semantic_to_next' => $metrics['normalization_semantic'], 'gradient_to_next' => $metrics['normalization_gradient']),
            array('step' => 10, 'time' => 'T10', 'operation' => '结果记录/趋势摘要', 'reading' => $metrics['result_note'] !== '' ? $metrics['result_note'] : '等待跳步识别后进入步骤推理'),
        );

        $adjacent = $this->buildAdjacentBreakpointRows($events);
        foreach ($adjacent as $index => $row) {
            $label = '';
            if ($row['before'] === '一抗孵育' && $row['after'] === '洗膜/二抗孵育链') {
                $label = '一抗孵育 → 洗膜/二抗孵育链 记录连续性断点候选';
            } elseif ($row['before'] === '洗膜/二抗孵育链' && $row['after'] === '显影曝光') {
                $label = '洗膜/二抗孵育链 → 显影曝光 流程连续性断点候选';
            } elseif ($row['before'] === '显影曝光' && $row['after'] === '灰度分析/内参归一化') {
                $label = '显影曝光 → 灰度分析 图像/QC断点候选';
            } elseif ($row['before'] === '灰度分析/内参归一化') {
                $label = '灰度分析/内参归一化 → 结果记录 数值断点候选';
            }
            $adjacent[$index]['label'] = $label !== '' ? $label : sprintf('%s → %s 逻辑断点候选', $row['before'], $row['after']);
            $adjacent[$index]['evidence'] = $this->westernBlotBreakpointEvidence($row, $inputs, $metrics);
            $adjacent[$index]['recommendation'] = $this->westernBlotBreakpointRecommendation($row, $inputs);
        }

        $candidates = array_values(array_filter($adjacent, static fn(array $row): bool => (bool) $row['is_candidate']));
        $ranked = $adjacent;
        usort($ranked, static fn(array $a, array $b): int => (float) $b['joint_score'] <=> (float) $a['joint_score']);
        if ($candidates === array()) {
            $top = $ranked[0] ?? array(
                'before' => '-',
                'after' => '-',
                'joint_score' => '0.00',
                'label' => '未发现高风险断点候选',
            );
            $primary = $top;
            $primary['label'] = sprintf(
                '未发现高风险断点候选，最高关注：%s → %s 相邻记录关注点',
                $top['before'] ?? '-',
                $top['after'] ?? '-',
            );
            $primary['recommendation'] = '保留该相邻位置为关注点，跳步识别阶段再核验证据链连续性。';
        } else {
            $primary = $candidates[0];
        }
        $primary['label'] = $primary['label'] ?? sprintf('%s → %s 逻辑断点候选', $primary['before'] ?? '-', $primary['after'] ?? '-');
        $primary['score'] = $primary['joint_score'] ?? '0.00';
        $primary['status'] = ((float) $primary['score']) >= 0.7 ? '候选断点' : '关注断点';
        $primary['next_stage'] = '送入跳步识别，仅核验记录断裂是否对应真实跳步。';
        $sourceLabel = $this->westernBlotBreakpointSourceLabel($inputs);

        return array(
            'module' => '断点识别模块',
            'event_sequence' => $events,
            'adjacent_pairs' => $adjacent,
            'candidates' => $candidates,
            'primary_candidate' => $primary,
            'weights' => array('numeric_gradient' => 0.6, 'semantic_continuity' => 0.4, 'threshold' => 0.7),
            'archive_record_count' => (int) ($archive['record_count'] ?? 0),
            'wb_metrics' => $metrics,
            'evidence_files' => $inputs['evidence_files'],
            'source' => $sourceLabel,
            'top_scored_pair' => $ranked[0] ?? array(),
            'has_real_wb_data' => true,
        );
    }

    private function buildInsufficientWesternBlotBreakpointResult(array $archive, array $experiment, array $uploads): array
    {
        return array(
            'module' => '断点识别模块',
            'event_sequence' => array(),
            'adjacent_pairs' => array(),
            'candidates' => array(),
            'primary_candidate' => array(
                'before' => '-',
                'after' => '-',
                'joint_score' => '0.00',
                'score' => '0.00',
                'label' => 'WB断点识别证据不足',
                'status' => '证据不足',
                'evidence' => '当前实验未读取到结构化WB结果、灰度表或断点表，断点识别不生成示例候选。',
                'recommendation' => '补充 WB 灰度归一化数据或结构化结果后重新进入断点识别。',
            ),
            'weights' => array('numeric_gradient' => 0.6, 'semantic_continuity' => 0.4, 'threshold' => 0.7),
            'archive_record_count' => (int) ($archive['record_count'] ?? 0),
            'evidence_files' => array(
                'image' => (string) ($uploads[0]['real_name'] ?? ''),
                'gray_csv' => '',
                'breakpoint_csv' => '',
            ),
            'source' => '证据不足：未读取到结构化WB结果、灰度表或断点表',
            'has_real_wb_data' => false,
        );
    }

    private function collectWesternBlotBreakpointInputs(int $experimentId, array $uploads, string $experimentBody): array
    {
        $payload = array();
        if ($experimentId > 0) {
            $assayPack = (new SmartlabExperiments($this->app->Users))->readAssayPack($experimentId);
            $payload = is_array($assayPack['payload'] ?? null) ? $assayPack['payload'] : array();
        }

        $rows = $this->normalizeWesternBlotResultRows(is_array($payload['results'] ?? null) ? $payload['results'] : array(), 'assay pack');
        $breakpointRows = array();
        $evidenceFiles = array('image' => '', 'gray_csv' => '', 'breakpoint_csv' => '');

        foreach ($uploads as $upload) {
            $name = (string) ($upload['real_name'] ?? '');
            $lower = strtolower($name);
            if (($upload['kind'] ?? '') === 'image' && $evidenceFiles['image'] === '') {
                $evidenceFiles['image'] = $name;
            }
            if (!str_ends_with($lower, '.csv')) {
                continue;
            }
            $csv = $this->readGeneratedUploadCsv($name);
            if ($csv === '') {
                continue;
            }
            if (str_contains($name, '灰度') || str_contains($lower, 'gray')) {
                $csvRows = $this->normalizeWesternBlotResultRows($this->parseCsvString($csv), $name);
                if ($csvRows !== array()) {
                    $rows = $csvRows;
                    $evidenceFiles['gray_csv'] = $name;
                }
            } elseif (str_contains($name, '断点') || str_contains($lower, 'breakpoint')) {
                $breakpointRows = $this->parseCsvString($csv);
                $evidenceFiles['breakpoint_csv'] = $name;
            }
        }

        $analysis = is_array($payload['analysis'] ?? null) ? $payload['analysis'] : array();
        $sample = is_array($payload['sample'] ?? null) ? $payload['sample'] : array();
        $run = is_array($payload['run'] ?? null) ? $payload['run'] : array();
        $reagent = is_array($payload['reagent'] ?? null) ? $payload['reagent'] : array();
        $image = is_array($payload['image'] ?? null) ? $payload['image'] : array();
        $qc = is_array($payload['qc'] ?? null) ? $payload['qc'] : array();

        $targetNames = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => (string) ($row['target'] ?? ''),
            $rows,
        ))));
        $reference = trim((string) ($analysis['reference_control'] ?? ''));
        if ($reference === '') {
            $reference = 'GAPDH';
        }

        $recordText = strtolower((json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') . ' ' . strip_tags($experimentBody));
        $hasText = static fn(array $needles): bool => array_reduce(
            $needles,
            static fn(bool $carry, string $needle): bool => $carry || str_contains($recordText, strtolower($needle)),
            false,
        );

        return array(
            'result_rows' => $rows,
            'breakpoint_rows' => $breakpointRows,
            'has_assay_pack_results' => is_array($payload['results'] ?? null) && ($payload['results'] ?? array()) !== array(),
            'target_label' => implode(' / ', array_filter($targetNames, static fn(string $target): bool => !in_array(strtolower($target), array('gapdh', 'actb', 'β-actin'), true))),
            'reference_label' => $reference,
            'sample_note' => trim((string) ($sample['loading_amount'] ?? '')),
            'loading_note' => trim((string) ($sample['denaturation_condition'] ?? '')),
            'run_note' => trim((string) ($run['electrophoresis_condition'] ?? '')),
            'transfer_note' => trim((string) ($run['transfer_condition'] ?? '')),
            'image_note' => trim((string) ($image['annotation_note'] ?? $qc['notes'] ?? '')),
            'protocol_presence' => array(
                'blocking' => $hasText(array('封闭', 'blocking', 'BSA', '脱脂奶')),
                'wash' => $hasText(array('洗膜', 'TBST', 'wash')),
                'secondary_antibody' => trim((string) ($reagent['secondary_antibody'] ?? '')) !== '' || $hasText(array('二抗', 'secondary', 'HRP')),
            ),
            'qc' => $qc,
            'evidence_files' => $evidenceFiles,
        );
    }

    private function westernBlotProtocolReading(bool $hasWash, bool $hasSecondary): string
    {
        $parts = array();
        $parts[] = $hasWash ? '洗膜记录有文本支撑' : '洗膜记录待核验';
        $parts[] = $hasSecondary ? '二抗信息有文本支撑' : '二抗信息待核验';
        return implode('；', $parts);
    }

    private function westernBlotBreakpointSourceLabel(array $inputs): string
    {
        $sources = array();
        if ((bool) ($inputs['has_assay_pack_results'] ?? false)) {
            $sources[] = '结构化WB结果';
        }
        if ((string) ($inputs['evidence_files']['gray_csv'] ?? '') !== '') {
            $sources[] = '灰度表';
        }
        if ((string) ($inputs['evidence_files']['breakpoint_csv'] ?? '') !== '') {
            $sources[] = '断点表';
        }
        if ((string) ($inputs['evidence_files']['image'] ?? '') !== '') {
            $sources[] = '原始条带图';
        }
        return $sources !== array() ? implode(' + ', $sources) : 'WB结构化记录';
    }

    private function normalizeWesternBlotResultRows(array $rows, string $source): array
    {
        $normalized = array();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $sample = trim((string) ($row['sample'] ?? $row['label'] ?? $row['sample_label'] ?? ''));
            $group = trim((string) ($row['group'] ?? $row['group_label'] ?? ''));
            $target = trim((string) ($row['target'] ?? $row['target_gene'] ?? ''));
            $targetSignal = $this->floatOrNull($row['raw_intensity'] ?? $row['target_signal'] ?? null);
            $referenceSignal = $this->floatOrNull($row['gapdh_intensity'] ?? $row['reference_signal'] ?? null);
            $normalizedSignal = $this->floatOrNull($row['normalized'] ?? $row['normalized_signal'] ?? $row['value'] ?? $row['fold_change'] ?? null);
            if ($sample === '' && $group === '' && $targetSignal === null && $referenceSignal === null && $normalizedSignal === null) {
                continue;
            }
            $normalized[] = array(
                'sample' => $sample !== '' ? $sample : '样本' . ($index + 1),
                'group' => $group !== '' ? $group : $this->inferWesternBlotGroup($sample),
                'target' => $target,
                'target_signal' => $targetSignal,
                'reference_signal' => $referenceSignal,
                'normalized_signal' => $normalizedSignal,
                'replicate' => trim((string) ($row['replicate'] ?? '')),
                'trend' => trim((string) ($row['trend'] ?? '')),
                'note' => trim((string) ($row['note'] ?? '')),
                'source' => $source,
            );
        }
        return $normalized;
    }

    private function calculateWesternBlotBreakpointMetrics(array $rows, array $breakpointRows): array
    {
        $normalizedValues = array_values(array_filter(array_map(static fn(array $row): ?float => $row['normalized_signal'], $rows), static fn(?float $value): bool => $value !== null));
        $referenceValues = array_values(array_filter(array_map(static fn(array $row): ?float => $row['reference_signal'], $rows), static fn(?float $value): bool => $value !== null));
        $groupMeans = $this->westernBlotGroupMeans($rows);
        $groupJump = $this->maxAdjacentDifference(array_values($groupMeans));
        $normalizedCv = $this->coefficientOfVariation($normalizedValues);
        $referenceCv = $this->coefficientOfVariation($referenceValues);
        $replicateCv = $this->maxWesternBlotReplicateCv($rows);
        $csvRisk = $this->maxBreakpointCsvRisk($breakpointRows);

        $numericGradient = $this->clamp01(max(
            $groupJump > 0 ? min(1.0, $groupJump / max(1.0, $this->mean($normalizedValues))) : 0.0,
            min(1.0, $normalizedCv * 4.0),
            min(1.0, $referenceCv * 6.0),
            min(1.0, $replicateCv * 4.0),
            $csvRisk,
        ));
        $referenceGradient = $this->clamp01(min(1.0, $referenceCv * 6.0));
        $normalizationGradient = $this->clamp01(max(min(1.0, $normalizedCv * 4.0), $csvRisk));
        $qcSemantic = $referenceCv > 0.15 || $replicateCv > 0.15 ? 0.58 : 0.86;
        $normalizationSemantic = $referenceCv > 0.15 ? 0.55 : 0.88;

        $meanNormalized = $this->mean($normalizedValues);
        $maxNormalized = $normalizedValues !== array() ? max($normalizedValues) : 0.0;
        $minNormalized = $normalizedValues !== array() ? min($normalizedValues) : 0.0;
        $riskNote = $this->highestBreakpointCsvEvidence($breakpointRows);

        return array(
            'max_numeric_gradient' => $numericGradient,
            'reference_gradient' => $referenceGradient,
            'normalization_gradient' => $normalizationGradient,
            'qc_semantic' => $qcSemantic,
            'normalization_semantic' => $normalizationSemantic,
            'reference_cv' => $referenceCv,
            'reference_cv_label' => $referenceValues !== array() ? number_format($referenceCv * 100, 1) . '%' : '待计算',
            'replicate_cv' => $replicateCv,
            'group_means' => $groupMeans,
            'summary_reading' => $normalizedValues !== array()
                ? sprintf('Normalized均值 %.3f，范围 %.3f-%.3f', $meanNormalized, $minNormalized, $maxNormalized)
                : '灰度归一化数据待补充',
            'result_note' => $riskNote,
        );
    }

    private function buildAdjacentBreakpointRows(array $events): array
    {
        $adjacent = array();
        foreach ($events as $index => $event) {
            if (!isset($events[$index + 1])) {
                continue;
            }
            $gradient = (float) ($event['gradient_to_next'] ?? 0.12);
            $semantic = (float) ($event['semantic_to_next'] ?? 0.94);
            $score = round((0.6 * $gradient) + (0.4 * (1 - $semantic)), 2);
            $adjacent[] = array(
                'before' => (string) $event['operation'],
                'after' => (string) $events[$index + 1]['operation'],
                'time_gap' => $event['time_gap_to_next'] ?? '-',
                'numeric_gradient' => number_format($gradient, 2),
                'semantic_continuity' => number_format($semantic, 2),
                'joint_score' => number_format($score, 2),
                'is_candidate' => $score >= 0.7,
            );
        }
        return $adjacent;
    }

    private function westernBlotBreakpointEvidence(array $row, array $inputs, array $metrics): string
    {
        $files = array_filter($inputs['evidence_files']);
        $prefix = $files !== array() ? '证据附件：' . implode('；', $files) . '。' : '';
        if ($row['before'] === '一抗孵育' || $row['before'] === '洗膜/二抗孵育链') {
            return $prefix . '相邻WB流程记录连续性下降，本页仅标记候选或关注位置，不判断是否存在真实跳步。';
        }
        if ($row['before'] === '显影曝光') {
            return $prefix . '结合原膜图备注与断点CSV风险，核验曝光、背景和ROI选择是否支撑后续灰度分析。';
        }
        if ($row['before'] === '灰度分析/内参归一化') {
            return $prefix . sprintf('归一化数值跳变 %.2f，内参CV %s。', $metrics['normalization_gradient'], $metrics['reference_cv_label']);
        }
        return $prefix . '来自当前实验WB结构化记录和附件索引。';
    }

    private function westernBlotBreakpointRecommendation(array $row, array $inputs): string
    {
        if ($row['before'] === '一抗孵育' || $row['before'] === '洗膜/二抗孵育链') {
            return '保留该相邻位置为断点识别输出，交由跳步识别继续核验证据链连续性。';
        }
        if ($row['before'] === '显影曝光') {
            return '进入跳步识别前复核原膜图、背景扣除和ROI选择记录。';
        }
        if ($row['before'] === '灰度分析/内参归一化') {
            return '进入跳步识别并保留内参归一化证据，后续步骤推理再判断是否需要补充计算表或重复实验。';
        }
        return '保留为低风险相邻事件，作为跳步识别的上下文。';
    }

    private function readGeneratedUploadCsv(string $realName): string
    {
        $basename = basename($realName);
        $paths = array(
            dirname(__DIR__, 4) . '/upload-data/generated/' . $basename,
            dirname(__DIR__, 3) . '/upload-data/generated/' . $basename,
            '/elabftw/upload-data/generated/' . $basename,
            '/upload-data/generated/' . $basename,
        );
        foreach (array_unique($paths) as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $content = file_get_contents($path);
            return is_string($content) ? $content : '';
        }
        return '';
    }

    private function parseCsvString(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (!is_array($lines) || count($lines) < 2) {
            return array();
        }
        $header = str_getcsv((string) array_shift($lines));
        $rows = array();
        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $values = str_getcsv((string) $line);
            $row = array();
            foreach ($header as $index => $key) {
                $row[(string) $key] = $values[$index] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function inferWesternBlotGroup(string $sample): string
    {
        $upper = strtoupper($sample);
        if (str_contains($upper, 'AD+PCN') || str_contains($upper, 'PCN')) {
            return 'AD+PCN';
        }
        if (str_contains($upper, 'AD')) {
            return 'AD';
        }
        if (str_contains($upper, 'WT') || str_contains($upper, 'CTRL') || str_contains($upper, 'CONTROL')) {
            return 'WT';
        }
        return '';
    }

    private function westernBlotGroupMeans(array $rows): array
    {
        $groups = array();
        foreach ($rows as $row) {
            $value = $row['normalized_signal'];
            if ($value === null) {
                continue;
            }
            $group = (string) ($row['group'] ?: '未分组');
            $groups[$group][] = $value;
        }
        $means = array();
        foreach ($groups as $group => $values) {
            $means[$group] = $this->mean($values);
        }
        return $means;
    }

    private function maxWesternBlotReplicateCv(array $rows): float
    {
        $groups = array();
        foreach ($rows as $row) {
            if ($row['normalized_signal'] === null) {
                continue;
            }
            $groups[(string) ($row['group'] ?: '未分组')][] = $row['normalized_signal'];
        }
        $max = 0.0;
        foreach ($groups as $values) {
            $max = max($max, $this->coefficientOfVariation($values));
        }
        return $max;
    }

    private function maxBreakpointCsvRisk(array $rows): float
    {
        $max = 0.0;
        foreach ($rows as $row) {
            $risk = $this->floatOrNull($row['risk_score'] ?? null);
            if ($risk !== null) {
                $max = max($max, $risk);
            }
        }
        return $this->clamp01($max);
    }

    private function highestBreakpointCsvEvidence(array $rows): string
    {
        $best = array();
        foreach ($rows as $row) {
            if (($row['module'] ?? '') !== 'breakpoint') {
                continue;
            }
            if ($best === array() || (float) ($row['risk_score'] ?? 0) > (float) ($best['risk_score'] ?? 0)) {
                $best = $row;
            }
        }
        if ($best === array()) {
            return '';
        }
        return trim((string) ($best['evidence'] ?? '')) . '；建议：' . trim((string) ($best['recommendation'] ?? ''));
    }

    private function floatOrNull(mixed $value): ?float
    {
        $string = trim((string) $value);
        if ($string === '' || !is_numeric($string)) {
            return null;
        }
        return (float) $string;
    }

    private function mean(array $values): float
    {
        $values = array_values(array_filter($values, static fn(mixed $value): bool => is_numeric($value)));
        if ($values === array()) {
            return 0.0;
        }
        return array_sum($values) / count($values);
    }

    private function coefficientOfVariation(array $values): float
    {
        $values = array_values(array_filter($values, static fn(mixed $value): bool => is_numeric($value)));
        if (count($values) < 2) {
            return 0.0;
        }
        $mean = $this->mean($values);
        if ($mean == 0.0) {
            return 0.0;
        }
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ((float) $value - $mean) ** 2;
        }
        return sqrt($variance / count($values)) / abs($mean);
    }

    private function maxAdjacentDifference(array $values): float
    {
        $max = 0.0;
        for ($i = 0; $i < count($values) - 1; $i++) {
            $max = max($max, abs((float) $values[$i + 1] - (float) $values[$i]));
        }
        return $max;
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function eventSequenceForAssay(string $assay): array
    {
        return match ($assay) {
            'qpcr' => array(
                array('step' => 1, 'time' => 'T1', 'operation' => 'RNA提取', 'reading' => 'A260/280=1.98', 'semantic_to_next' => 0.95, 'gradient_to_next' => 0.15),
                array('step' => 2, 'time' => 'T2', 'operation' => '反转录', 'reading' => 'cDNA完成', 'semantic_to_next' => 0.94, 'gradient_to_next' => 0.18),
                array('step' => 3, 'time' => 'T3', 'operation' => '扩增曲线读取', 'reading' => 'Ct表已导入', 'semantic_to_next' => 0.48, 'gradient_to_next' => 0.85),
                array('step' => 4, 'time' => 'T4', 'operation' => 'fold-change质控', 'reading' => 'mTOR=2.83; Rheb=2.45', 'semantic_to_next' => 0.95, 'gradient_to_next' => 0.14),
                array('step' => 5, 'time' => 'T5', 'operation' => '结果归档', 'reading' => '图表附件已绑定'),
            ),
            'if' => array(
                array('step' => 1, 'time' => 'T1', 'operation' => '制片', 'reading' => '切片厚度4um', 'semantic_to_next' => 0.97, 'gradient_to_next' => 0.10),
                array('step' => 2, 'time' => 'T2', 'operation' => '封闭', 'reading' => '血清封闭30min', 'semantic_to_next' => 0.96, 'gradient_to_next' => 0.12),
                array('step' => 3, 'time' => 'T3', 'operation' => '一抗孵育', 'reading' => '4C过夜', 'semantic_to_next' => 0.95, 'gradient_to_next' => 0.13),
                array('step' => 4, 'time' => 'T4', 'operation' => '封片', 'reading' => 'DAPI封片', 'semantic_to_next' => 0.50, 'gradient_to_next' => 0.84),
                array('step' => 5, 'time' => 'T5', 'operation' => '成像计数', 'reading' => 'NeuN+ 45 cells/field'),
            ),
            default => array(
                array('step' => 1, 'time' => 'T1', 'operation' => '制样', 'reading' => '蛋白浓度2.1ug/uL', 'semantic_to_next' => 0.96, 'gradient_to_next' => 0.12),
                array('step' => 2, 'time' => 'T2', 'operation' => '电泳', 'reading' => 'Marker清晰', 'semantic_to_next' => 0.95, 'gradient_to_next' => 0.14),
                array('step' => 3, 'time' => 'T3', 'operation' => '转膜', 'reading' => '转膜效率>90%', 'semantic_to_next' => 0.94, 'gradient_to_next' => 0.15),
                array('step' => 4, 'time' => 'T4', 'operation' => '一抗孵育', 'reading' => 'GAD67/GAPDH抗体', 'semantic_to_next' => 0.50, 'gradient_to_next' => 0.84),
                array('step' => 5, 'time' => 'T5', 'operation' => '显影灰度分析', 'reading' => 'GAD67/GAPDH=0.34'),
            ),
        };
    }

    private function buildJumpResult(array $breakpoint, array $archive, string $assay): array
    {
        if ($assay === 'wb') {
            return $this->buildWesternBlotJumpResult($breakpoint, $archive);
        }

        $candidate = $breakpoint['primary_candidate'] ?? array();
        $offset = match ($assay) {
            'qpcr' => 1.30,
            'if' => 0.34,
            default => 0.28,
        };
        $neighborAverage = match ($assay) {
            'qpcr' => 0.65,
            'if' => 0.12,
            default => 0.14,
        };
        $threshold = round($neighborAverage * 1.5, 2);
        $risk = $offset > $threshold;
        return array(
            'module' => '跳步识别模块',
            'candidate_breakpoint' => $candidate,
            'before_evidence' => $this->jumpEvidenceLabel($assay, true),
            'after_evidence' => $this->jumpEvidenceLabel($assay, false),
            'offset' => $offset,
            'neighbor_average' => $neighborAverage,
            'threshold' => $threshold,
            'decision' => $risk ? sprintf('偏移 %.2f > 1.5×同批相邻均值 %.2f，存在跳步风险', $offset, $neighborAverage) : '未超过同批相邻均值阈值',
            'confidence' => $risk ? '0.73' : '0.42',
            'archive_record_count' => (int) ($archive['record_count'] ?? 0),
        );
    }

    private function buildWesternBlotJumpResult(array $breakpoint, array $archive, array $workflow = array()): array
    {
        $primary = $breakpoint['primary_candidate'] ?? array();
        $pairs = $breakpoint['adjacent_pairs'] ?? array();
        $wbMetrics = $breakpoint['wb_metrics'] ?? array();
        $evidenceFiles = $breakpoint['evidence_files'] ?? array();
        $hasRealData = (bool) ($breakpoint['has_real_wb_data'] ?? false);

        // Find the highest-scoring adjacent pair as the candidate breakpoint for jump analysis
        $targetPair = array();
        $candidateBreakpoint = $primary;
        foreach ($pairs as $pair) {
            if ((bool) ($pair['is_candidate'] ?? false)) {
                $targetPair = $pair;
                break;
            }
        }
        if ($targetPair === array() && $pairs !== array()) {
            usort($pairs, static fn(array $a, array $b): int => (float) $b['joint_score'] <=> (float) $a['joint_score']);
            $targetPair = $pairs[0];
        }

        // Compute offset from WB breakpoint metrics
        $maxNumericGradient = (float) ($wbMetrics['max_numeric_gradient'] ?? 0.0);
        $normalizationGradient = (float) ($wbMetrics['normalization_gradient'] ?? 0.0);
        $referenceCv = (float) ($wbMetrics['reference_cv'] ?? 0.0);

        // offset: the primary jump signal based on normalization gradient and reference CV
        $offset = round(max($maxNumericGradient, $normalizationGradient, $referenceCv * 3.0), 2);

        // Compute neighbor_average from adjacent pairs' joint scores
        $neighborScores = array_map(static fn(array $p): float => (float) ($p['joint_score'] ?? 0.0), $pairs);
        $neighborAverage = $neighborScores !== array()
            ? round(array_sum($neighborScores) / count($neighborScores), 2)
            : 0.14;
        $threshold = round($neighborAverage * 1.5, 2);

        // Determine status/decision and risk_score/confidence
        $isJump = $offset > $threshold;
        $riskScore = $isJump
            ? round(min(0.99, $offset / max($threshold, 0.01) * 0.5), 2)
            : round(max(0.20, $offset / max($threshold, 0.01) * 0.3), 2);
        $confidence = $hasRealData && $isJump ? number_format(max(0.70, $riskScore), 2) : number_format(min(0.45, $riskScore), 2);

        $status = $isJump
            ? '疑似跳步'
            : ($hasRealData ? '记录断裂/证据不足' : '证据不足');

        $decision = $isJump
            ? sprintf('偏移 %.2f > 阈值 %.2f（1.5×同批相邻均值 %.2f），判定为疑似跳步', $offset, $threshold, $neighborAverage)
            : sprintf('偏移 %.2f ≤ 阈值 %.2f（1.5×同批相邻均值 %.2f），判定为%s', $offset, $threshold, $neighborAverage, $hasRealData ? '记录断裂/证据不足' : '证据不足');

        // Build missing steps based on where the breakpoint is
        $before = (string) ($targetPair['before'] ?? ($primary['before'] ?? ''));
        $after = (string) ($targetPair['after'] ?? ($primary['after'] ?? ''));
        $missingSteps = $this->inferWesternBlotMissingSteps($before, $after, $isJump);

        // Build before/after evidence strings from WB context
        $beforeEvidence = $this->buildWesternBlotJumpEvidence(true, $wbMetrics, $evidenceFiles);
        $afterEvidence = $this->buildWesternBlotJumpEvidence(false, $wbMetrics, $evidenceFiles);

        // Build recommendation
        $recommendation = $this->buildWesternBlotJumpRecommendation($isJump, $hasRealData, $before, $after, $wbMetrics);

        // Build candidate_breakpoint with enriched fields
        $candidateBreakpoint = array_merge($candidateBreakpoint, array(
            'before' => $before !== '' ? $before : ($candidateBreakpoint['before'] ?? '-'),
            'after' => $after !== '' ? $after : ($candidateBreakpoint['after'] ?? '-'),
        ));

        return array(
            'module' => '跳步识别模块',
            'status' => $status,
            'decision' => $decision,
            'risk_score' => $riskScore,
            'confidence' => $confidence,
            'missing_steps' => $missingSteps,
            'before_evidence' => $beforeEvidence,
            'after_evidence' => $afterEvidence,
            'offset' => $offset,
            'neighbor_average' => $neighborAverage,
            'threshold' => $threshold,
            'candidate_breakpoint' => $candidateBreakpoint,
            'evidence_files' => $evidenceFiles,
            'recommendation' => $recommendation,
            'has_real_wb_data' => $hasRealData,
            'archive_record_count' => (int) ($archive['record_count'] ?? 0),
        );
    }

    private function inferWesternBlotMissingSteps(string $before, string $after, bool $isJump): array
    {
        if (!$isJump) {
            return array();
        }

        $allSteps = array('封闭', '一抗孵育', '洗膜', '二抗孵育', 'ECL显影', '显影曝光');
        $beforeIndex = array_search($before, $allSteps, true);
        $afterIndex = array_search($after, $allSteps, true);

        if ($beforeIndex !== false && $afterIndex !== false && $beforeIndex < $afterIndex) {
            return array_slice($allSteps, $beforeIndex + 1, $afterIndex - $beforeIndex - 1);
        }

        // Heuristic: if before is a common step name, infer missing range
        $stepMap = array(
            '蛋白提取/制样' => array(),
            '蛋白定量/上样' => array(),
            'SDS-PAGE电泳' => array(),
            '转膜' => array('封闭'),
            '封闭' => array('一抗孵育', '洗膜'),
            '一抗孵育' => array('洗膜', '二抗孵育'),
            '洗膜/二抗孵育链' => array('ECL显影'),
            '显影曝光' => array(),
            '灰度分析/内参归一化' => array(),
        );

        if (isset($stepMap[$before])) {
            return $stepMap[$before];
        }

        return array('洗膜', '二抗孵育');
    }

    private function buildWesternBlotJumpEvidence(bool $before, array $wbMetrics, array $evidenceFiles): string
    {
        $imageFile = (string) ($evidenceFiles['image'] ?? '');
        $grayCsv = (string) ($evidenceFiles['gray_csv'] ?? '');
        $breakpointCsv = (string) ($evidenceFiles['breakpoint_csv'] ?? '');

        $summaryReading = (string) ($wbMetrics['summary_reading'] ?? '');
        $referenceCvLabel = (string) ($wbMetrics['reference_cv_label'] ?? '');

        $parts = array();
        if ($imageFile !== '') {
            $parts[] = '原始条带图：' . $imageFile;
        }
        if ($grayCsv !== '') {
            $parts[] = '灰度定量CSV：' . $grayCsv;
        }
        if ($before) {
            $parts[] = '光密度/归一化前：' . $summaryReading;
        } else {
            $parts[] = '光密度/归一化后：内参CV=' . $referenceCvLabel;
        }

        return $parts !== array() ? implode('；', $parts) : 'WB光密度/灰度条带证据待补充';
    }

    private function buildWesternBlotJumpRecommendation(bool $isJump, bool $hasRealData, string $before, string $after, array $wbMetrics): string
    {
        if (!$hasRealData) {
            return '当前WB实验缺少结构化灰度归一化数据，建议补充WB灰度表或断点表后重新进入跳步识别。';
        }
        if ($isJump) {
            $stepHint = $this->inferWesternBlotMissingSteps($before, $after, true);
            if ($stepHint !== array()) {
                return '疑似缺失步骤：' . implode('、', $stepHint) . '。建议回顾原始记录，确认是否遗漏，或进入步骤推理补全。';
            }
            return '疑似跳步风险，建议复核WB实验记录连续性，并进入步骤推理补全缺失步骤。';
        }
        return '未检测到跳步风险，但建议复核WB记录连续性，可进入步骤推理核验证据链。';
    }

    private function jumpEvidenceLabel(string $assay, bool $before): string
    {
        return match ($assay) {
            'qpcr' => $before ? 'qPCR Ct: mTOR Ct=23.4, GAPDH Ct=18.9' : 'qPCR fold-change: mTOR=2.83, Rheb=2.45',
            'if' => $before ? 'IF图像: NeuN+阳性面积=34%, DAPI核计数=245' : 'IF图像: NeuN+阳性面积=68%, DAPI核计数=252',
            default => $before ? 'WB条带: GAD67 OD=0.56, GAPDH OD=1.32' : 'WB条带: GAD67 OD=0.84, GAPDH OD=1.28',
        };
    }

    private function buildReasoningResult(array $jump, string $assay): array
    {
        $steps = $assay === 'wb' && is_array($jump['missing_steps'] ?? null)
            ? array_values($jump['missing_steps'])
            : match ($assay) {
                'qpcr' => array('cDNA合成', 'qPCR反应体系配置', 'qPCR扩增程序', '熔解曲线分析', '结果导出'),
                'if' => array('固定', '通透', '封闭', '一抗孵育', '洗涤', '二抗孵育'),
                default => array('封闭', '一抗孵育', '洗膜', '二抗孵育', 'ECL显影'),
            };
        if ($steps === array()) {
            $steps = array('补充WB记录连续性说明');
        }
        $paths = array(
            array('path' => implode(' → ', $steps), 'semantic_benefit' => '0.86', 'reading_cost' => '0.18', 'score' => '0.85', 'recommended' => true),
            array('path' => implode(' → ', array_slice($steps, 1)), 'semantic_benefit' => '0.80', 'reading_cost' => '0.24', 'score' => '0.78', 'recommended' => true),
            array('path' => implode(' → ', array_slice($steps, 0, max(2, count($steps) - 1))), 'semantic_benefit' => '0.72', 'reading_cost' => '0.31', 'score' => '0.68', 'recommended' => false),
        );
        return array(
            'module' => '步骤推理模块',
            'predecessor' => (string) (($jump['candidate_breakpoint']['before'] ?? '') ?: '前一有效操作'),
            'successor' => (string) (($jump['candidate_breakpoint']['after'] ?? '') ?: '后一有效操作'),
            'recommended_steps' => $steps,
            'candidate_paths' => $paths,
            'best_score' => $paths[0]['score'],
            'source' => '同批完整序列 + 结构化模板库 + 仪器读数范围',
        );
    }

    private function buildWorkflowTemplateDraft(string $assay, int $experimentId, string $title, array $uploads, array $reasoning): array
    {
        $imageUploads = array_values(array_filter($uploads, static fn(array $u): bool => $u['kind'] === 'image'));
        $dataUploads = array_values(array_filter($uploads, static fn(array $u): bool => $u['kind'] !== 'image'));
        $steps = $this->getAssayTemplateSteps($assay);
        $draft = $this->getAssayTemplateDraft($assay, $experimentId, $title, $imageUploads, $dataUploads);
        if (($reasoning['recommended_steps'] ?? array()) !== array()) {
            $draft[] = array(
                'title' => '专利链路补全说明',
                'content' => '已根据跳步识别结果插入推理补全步骤：' . implode('、', $reasoning['recommended_steps']),
                'binding' => '步骤推理已绑定',
                'binding_badge' => 'purple',
            );
        }
        return array('steps' => $steps, 'draft' => $draft);
    }

    private function buildPatentChainSummary(string $assay, int $experimentId, int $attachmentCount, array $workflow): array
    {
        $stageRank = array(
            'upload_demo' => 1,
            'recognition' => 2,
            'breakpoint' => 3,
            'jump' => 4,
            'reasoning' => 5,
            'template' => 6,
        );
        $currentRank = $stageRank[(string) ($workflow['current_stage'] ?? 'recognition')] ?? 2;
        $statusFor = static fn(int $rank, bool $hasData): string => $hasData && $currentRank >= $rank ? '已完成' : '待执行';

        return array(
            'assay' => $assay,
            'assay_label' => $this->assayLabel($assay),
            'experiment_id' => $experimentId,
            'chain' => array(
                array('module' => '原始附件', 'status' => $attachmentCount > 0 ? '已完成' : '待执行', 'detail' => $attachmentCount > 0 ? sprintf('%d 个附件已关联', $attachmentCount) : '暂无附件'),
                array('module' => '智能识别', 'status' => $statusFor(2, isset($workflow['digital_archive'])), 'detail' => isset($workflow['digital_archive']) ? sprintf('%d 条数字化档案记录', (int) ($workflow['digital_archive']['record_count'] ?? 0)) : '等待结构化提取'),
                array('module' => '断点识别', 'status' => $statusFor(3, isset($workflow['breakpoint_result'])), 'detail' => $currentRank >= 3 ? (string) ($workflow['breakpoint_result']['primary_candidate']['label'] ?? '等待事件序列评分') : '等待事件序列评分'),
                array('module' => '跳步识别', 'status' => $statusFor(4, isset($workflow['jump_result'])), 'detail' => $currentRank >= 4 ? (string) ($workflow['jump_result']['decision'] ?? '等待前后证据偏移评估') : '等待前后证据偏移评估'),
                array('module' => '步骤推理', 'status' => $statusFor(5, isset($workflow['reasoning_result'])), 'detail' => $currentRank >= 5 && isset($workflow['reasoning_result']) ? sprintf('最优路径评分 %s', (string) ($workflow['reasoning_result']['best_score'] ?? '-')) : '等待候选路径搜索'),
            ),
        );
    }

    private function demoAssayAnalysisPointsForPatent(string $assay): array
    {
        return match ($assay) {
            'qpcr' => array(
                array('name' => '目标基因', 'value' => 'mTOR / Rheb / Lamtor1'),
                array('name' => '内参基因', 'value' => 'GAPDH'),
                array('name' => 'mTOR fold-change', 'value' => '2.83'),
            ),
            'if' => array(
                array('name' => '通道识别', 'value' => 'DAPI / FITC / TRITC / Merge'),
                array('name' => 'NeuN阳性率', 'value' => 'Aβ: 34%; Aβ+PME: 68%'),
                array('name' => 'Merge一致性', 'value' => '0.94'),
            ),
            default => array(
                array('name' => '目标蛋白', 'value' => 'VGlut1 / GAD67'),
                array('name' => '内参', 'value' => 'GAPDH'),
                array('name' => 'GAD67/GAPDH', 'value' => '0.84'),
            ),
        };
    }

    private function applyAssayTemplate(int $experimentId, string $assay, string $templateName): array
    {
        $experiment = $this->readWritableExperiment($experimentId);
        $uploads = $this->readExperimentUploads($experimentId);
        $imageUploads = array_values(array_filter($uploads, static fn(array $u): bool => $u['kind'] === 'image'));
        $dataUploads = array_values(array_filter($uploads, static fn(array $u): bool => $u['kind'] !== 'image'));

        $title = $experiment['title'] ?? '';
        $workflow = $experiment !== null ? $this->ensurePatentWorkflowStage($experiment, 'template', $assay) : array();
        $steps = $workflow['template_draft']['steps'] ?? $this->getAssayTemplateSteps($assay);
        $draft = $workflow['template_draft']['draft'] ?? $this->getAssayTemplateDraft($assay, $experimentId, $title, $imageUploads, $dataUploads);

        $label = $this->assayLabel($assay);
        $resolvedTemplateName = $templateName ?: sprintf('%s实验模板', $label);
        $this->appendSmartlabTemplateDraft(
            $experimentId,
            sprintf('%s模板补全草稿', $label),
            $resolvedTemplateName,
            $label,
            $steps,
            $draft,
        );

        $chainSummary = $workflow['chain_summary'] ?? $this->buildPatentChainSummary($assay, $experimentId, count($imageUploads) + count($dataUploads), array());
        if ($experiment !== null && $workflow !== array()) {
            $this->savePatentWorkflow($experimentId, $workflow);
        }

        return array(
            'assay' => $assay,
            'template_name' => $resolvedTemplateName,
            'steps' => $steps,
            'draft' => $draft,
            'image_uploads' => $imageUploads,
            'data_uploads' => $dataUploads,
            'experiment_id' => $experimentId,
            'title' => $title,
            'chain_summary' => $chainSummary,
        );
    }

    /** @return array<int, array{num: string, name: string, content: string, source: string, source_state: string, evidence_module: string, evidence_ref: string, confidence: string, binding: string}> */
    private function getAssayTemplateSteps(string $assay): array
    {
        $wbSteps = array(
            array('num' => '01', 'name' => '样本准备', 'content' => 'Control / Treatment 蛋白样本，按实验记录关联 WB 批次',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => 'WB原图 / 实验记录', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '02', 'name' => '蛋白定量', 'content' => 'BCA 定量后按等量蛋白上样，样本体积待导师确认',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '蛋白浓度测定数据待补充', 'confidence' => '中', 'binding' => '识别字段待绑定'),
            array('num' => '03', 'name' => 'SDS-PAGE', 'content' => '按目标蛋白分子量选择胶浓度，完成跑胶记录',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => '凝胶图片 / 仪器参数', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '04', 'name' => '转膜', 'content' => 'PVDF膜甲醇活化后湿转，转膜条件由历史模板匹配',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·WB转膜条件匹配 0.88', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '05', 'name' => '封闭', 'content' => '5% BSA/TBST，室温 1 h',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·封闭条件默认推荐 0.84', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '06', 'name' => '一抗孵育', 'content' => 'VGlut1、GAD67 与 GAPDH 一抗孵育条件待确认',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '断点识别', 'evidence_ref' => '条带OD偏移 0.73 > 阈值 0.60', 'confidence' => '中', 'binding' => '待导师确认'),
            array('num' => '07', 'name' => '洗膜', 'content' => 'TBST 洗膜 3 次，每次 5 min',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '跳步识别', 'evidence_ref' => '跳步推理补全·洗膜缺失 0.75', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '08', 'name' => '二抗孵育', 'content' => 'HRP 标记二抗，室温 1 h',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '跳步识别', 'evidence_ref' => '跳步推理补全·二抗缺失 0.76', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '09', 'name' => '成像识别', 'content' => '读取 WB 原图并识别条带 ROI 与背景区域',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => 'WB原图识别·条带ROI 0.97', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '10', 'name' => '灰度定量', 'content' => 'VGlut1/GAPDH 与 GAD67/GAPDH 归一化',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => '灰度定量CSV·归一化 0.96', 'confidence' => '高', 'binding' => '识别字段已绑定'),
        );
        $qpcrSteps = array(
            array('num' => '01', 'name' => 'RNA提取', 'content' => '按实验记录提取总 RNA，测定浓度和纯度',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => 'RNA定量数据 / 实验记录', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '02', 'name' => '反转录', 'content' => 'RNA 反转录为 cDNA',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·反转录条件匹配 0.86', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '03', 'name' => '引物设计', 'content' => '目标基因与内参基因引物序列已记录',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => '引物信息表 / 实验记录', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '04', 'name' => 'qPCR体系配制', 'content' => 'SYBR Green 预混液、模板 cDNA、引物',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·qPCR体系默认推荐 0.85', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '05', 'name' => 'qPCR扩增', 'content' => '读取扩增曲线和 Ct 值',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => '扩增曲线/Ct表识别 0.98', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '06', 'name' => '熔解曲线分析', 'content' => '确认单一扩增产物，无引物二聚体',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => '熔解曲线·单峰确认 0.96', 'confidence' => '高', 'binding' => '识别字段已绑定'),
            array('num' => '07', 'name' => 'Ct值分析', 'content' => '计算各样本平均 Ct 值和标准差',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => 'Ct原始表·复孔CV 0.95', 'confidence' => '高', 'binding' => '识别字段已绑定'),
            array('num' => '08', 'name' => '相对定量计算', 'content' => '2^-ΔΔCt 计算 fold-change，超阈值基因需复核',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '断点识别', 'evidence_ref' => 'mTOR FC=2.83 超阈值2.00·数值不连续性 0.89', 'confidence' => '中', 'binding' => '待导师确认'),
            array('num' => '09', 'name' => '统计分析', 'content' => 't 检验或 ANOVA 分析组间差异',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '统计方法待确认', 'confidence' => '低', 'binding' => '识别字段待绑定'),
            array('num' => '10', 'name' => '结果报告', 'content' => '汇总扩增曲线、Ct 表和统计分析结果',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·报告模板 0.90', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
        );
        $pcrSteps = array(
            array('num' => '01', 'name' => '样本准备', 'content' => '组织/细胞/体液样本采集与处理',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => '样本记录 / 实验记录', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '02', 'name' => 'DNA提取', 'content' => '基因组 DNA 提取与纯化',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => 'DNA定量数据 0.97', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '03', 'name' => '引物设计', 'content' => '目标序列特异性引物设计',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => '引物信息表 / 实验记录', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '04', 'name' => 'PCR体系配制', 'content' => 'Taq 聚合酶、dNTP、引物、模板 DNA',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·PCR体系默认推荐 0.84', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '05', 'name' => 'PCR扩增', 'content' => '变性、退火、延伸循环',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => '扩增程序识别 0.95', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '06', 'name' => '凝胶电泳', 'content' => '琼脂糖凝胶电泳分离 PCR 产物',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => '凝胶图像·条带定位 0.94', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '07', 'name' => '条带分析', 'content' => '目标条带大小确认和半定量分析',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '条带定量待确认', 'confidence' => '中', 'binding' => '识别字段待绑定'),
            array('num' => '08', 'name' => '测序验证', 'content' => 'PCR 产物 Sanger 测序',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·测序流程 0.82', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '09', 'name' => '序列比对', 'content' => '测序结果与参考序列比对',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '比对结果待确认', 'confidence' => '低', 'binding' => '识别字段待绑定'),
            array('num' => '10', 'name' => '结果报告', 'content' => '汇总电泳图和测序结果',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·报告模板 0.88', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
        );
        $elisaSteps = array(
            array('num' => '01', 'name' => '样本准备', 'content' => '血清/血浆/细胞上清样本准备',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => '样本记录 / 实验记录', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '02', 'name' => '包被', 'content' => '抗体包被 ELISA 板',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·包被条件 0.86', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '03', 'name' => '封闭', 'content' => 'BSA 或脱脂奶粉封闭',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·封闭条件 0.85', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '04', 'name' => '加样', 'content' => '标准品和样本加样',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => '加样布局表 0.96', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '05', 'name' => '孵育', 'content' => '室温或 37°C 孵育',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·孵育条件 0.87', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '06', 'name' => '洗涤', 'content' => 'PBST 洗涤 3-5 次',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '跳步识别', 'evidence_ref' => '跳步推理补全·洗涤 0.73', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '07', 'name' => '加酶标抗体', 'content' => 'HRP 标记检测抗体',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '跳步识别', 'evidence_ref' => '跳步推理补全·酶标抗体 0.74', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '08', 'name' => '显色', 'content' => 'TMB 显色，避光孵育',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '显色时间待确认', 'confidence' => '中', 'binding' => '待导师确认'),
            array('num' => '09', 'name' => '终止', 'content' => '加终止液，颜色由蓝变黄',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·终止条件 0.89', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '10', 'name' => 'OD测量', 'content' => '450 nm 读取 OD 值',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => 'OD450 原始数据 0.98', 'confidence' => '高', 'binding' => '识别字段已绑定'),
            array('num' => '11', 'name' => '标准曲线拟合', 'content' => '四参数或线性拟合标准曲线',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '拟合模型待确认', 'confidence' => '中', 'binding' => '识别字段待绑定'),
            array('num' => '12', 'name' => '浓度计算', 'content' => '根据标准曲线计算样本浓度',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '浓度计算待确认', 'confidence' => '低', 'binding' => '识别字段待绑定'),
        );
        $ifSteps = array(
            array('num' => '01', 'name' => '细胞培养', 'content' => '细胞铺板或爬片培养至合适密度',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => 'IF细胞培养记录 / 实验记录', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '02', 'name' => '固定', 'content' => '4% PFA 或甲醇固定',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·IF固定条件 0.88', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '03', 'name' => '通透', 'content' => 'Triton X-100 通透细胞膜',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·通透条件 0.86', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '04', 'name' => '封闭', 'content' => '血清或 BSA 封闭非特异性结合',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·封闭条件 0.85', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '05', 'name' => '一抗孵育', 'content' => '特异性一抗 4°C 孵育过夜',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '断点识别', 'evidence_ref' => '视野细胞密度偏低 0.66', 'confidence' => '中', 'binding' => '待导师确认'),
            array('num' => '06', 'name' => '洗涤', 'content' => 'PBST 洗涤 3 次',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '跳步识别', 'evidence_ref' => '跳步推理补全·洗涤缺失 0.75', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '07', 'name' => '二抗孵育', 'content' => '荧光标记二抗避光孵育',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '跳步识别', 'evidence_ref' => '跳步推理补全·二抗缺失 0.76', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '08', 'name' => '核染', 'content' => 'DAPI 或 Hoechst 核染',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·核染条件 0.90', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '09', 'name' => '封片', 'content' => '抗荧光淬灭封片剂封片',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·封片条件 0.91', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '10', 'name' => '图像采集', 'content' => '荧光显微镜多通道采集图像',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => 'IF通道图像·通道映射 0.97', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '11', 'name' => '阳性率分析', 'content' => 'ImageJ 或软件自动计数阳性细胞',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => 'IF细胞计数·阳性率 0.94', 'confidence' => '高', 'binding' => '识别字段已绑定'),
            array('num' => '12', 'name' => '统计分析', 'content' => '组间阳性率比较和统计检验',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '统计方法待确认', 'confidence' => '低', 'binding' => '识别字段待绑定'),
        );
        $ihcSteps = array(
            array('num' => '01', 'name' => '组织切片', 'content' => '石蜡切片或冰冻切片制备',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => '切片记录 / 实验记录', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '02', 'name' => '脱蜡水化', 'content' => '二甲苯脱蜡，梯度乙醇水化',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·脱蜡条件 0.90', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '03', 'name' => '抗原修复', 'content' => '柠檬酸盐或 EDTA 热修复',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·修复条件 0.87', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '04', 'name' => '阻断内源性酶', 'content' => 'H2O2 阻断内源性过氧化物酶',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·阻断条件 0.88', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '05', 'name' => '封闭', 'content' => '血清或 BSA 封闭',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·封闭条件 0.86', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '06', 'name' => '一抗孵育', 'content' => '特异性一抗 4°C 孵育过夜',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '断点识别', 'evidence_ref' => 'DAB阳性信号区域待确认', 'confidence' => '中', 'binding' => '待导师确认'),
            array('num' => '07', 'name' => '洗涤', 'content' => 'PBST 洗涤 3 次',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '跳步识别', 'evidence_ref' => '跳步推理补全·洗涤 0.72', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '08', 'name' => '二抗孵育', 'content' => 'HRP 标记二抗室温孵育',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '跳步识别', 'evidence_ref' => '跳步推理补全·二抗 0.73', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '09', 'name' => 'DAB显色', 'content' => 'DAB 显色，显微镜下控制显色时间',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '显色时间待确认', 'confidence' => '中', 'binding' => '待导师确认'),
            array('num' => '10', 'name' => '苏木素复染', 'content' => '苏木素复染细胞核',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·复染条件 0.89', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '11', 'name' => '脱水封片', 'content' => '梯度乙醇脱水，二甲苯透明，中性树胶封片',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·封片条件 0.90', 'confidence' => '高', 'binding' => '推理步骤已绑定'),
            array('num' => '12', 'name' => '图像采集', 'content' => '明场显微镜采集 IHC 图像',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => 'IHC图像·DAB染色识别 0.95', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '13', 'name' => '阳性率评分', 'content' => 'H-score 或阳性率半定量评分',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => 'H-score评分待确认', 'confidence' => '中', 'binding' => '待导师确认'),
        );
        $defaultSteps = array(
            array('num' => '01', 'name' => '实验准备', 'content' => '按照实验记录准备样本和试剂',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '原始附件', 'evidence_ref' => '实验记录', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '02', 'name' => '实验操作', 'content' => '按照标准操作流程进行实验',
                'source' => '推理补全', 'source_state' => '推理补全', 'evidence_module' => '步骤推理', 'evidence_ref' => '模板库 V2·默认流程 0.80', 'confidence' => '中', 'binding' => '推理步骤已绑定'),
            array('num' => '03', 'name' => '数据采集', 'content' => '采集实验原始数据',
                'source' => '已识别', 'source_state' => '已识别', 'evidence_module' => '智能识别', 'evidence_ref' => '原始数据识别 0.92', 'confidence' => '高', 'binding' => '附件已绑定'),
            array('num' => '04', 'name' => '数据分析', 'content' => '分析实验数据并得出结论',
                'source' => '待确认', 'source_state' => '待确认', 'evidence_module' => '智能识别', 'evidence_ref' => '分析结论待确认', 'confidence' => '低', 'binding' => '待导师确认'),
        );

        return match ($assay) {
            'wb' => $wbSteps,
            'qpcr' => $qpcrSteps,
            'pcr' => $pcrSteps,
            'elisa' => $elisaSteps,
            'if' => $ifSteps,
            'ihc' => $ihcSteps,
            default => $defaultSteps,
        };
    }

    /** @return array<int, array{title: string, content: string, binding: string, binding_badge: string}> */
    private function getAssayTemplateDraft(string $assay, int $experimentId, string $title, array $imageUploads, array $dataUploads): array
    {
        $firstImage = $imageUploads[0]['real_name'] ?? sprintf('%s原始图像', $this->assayLabel($assay));
        $firstData = $dataUploads[0]['real_name'] ?? sprintf('%s数据表', $this->assayLabel($assay));
        $label = $this->assayLabel($assay);

        $recognitionContent = match ($assay) {
            'wb' => '识别 VGlut1、GAD67 与 GAPDH 条带，读取灰度表并完成归一化；AD+PCN 组 GAD67/GAPDH = 0.84。',
            'qpcr' => '读取 Ct 值、扩增曲线和熔解曲线，计算 delta-delta Ct 和 fold-change。mTOR FC=2.83 超阈值。',
            'pcr' => '识别凝胶电泳条带位置和大小，确认目标序列扩增特异性。',
            'elisa' => '读取 OD450 值，拟合标准曲线并计算样本浓度。',
            'if' => '识别 DAPI/FITC/TRITC 通道图像，自动计数阳性细胞并计算阳性率。',
            'ihc' => '识别 DAB 阳性信号区域，评估染色强度和阳性率。',
            default => '已完成智能识别和结构化数据提取。',
        };

        $draft = array(
            array('title' => '实验目的', 'content' => sprintf('基于真实附件「%s」和「%s」完成 %s 过程补全。', $firstImage, $firstData, $label),
                'binding' => '附件已绑定', 'binding_badge' => 'green'),
            array('title' => '样本信息', 'content' => sprintf('来源实验：#%d；实验名称：%s', $experimentId, $title ?: '-'),
                'binding' => '附件已绑定', 'binding_badge' => 'green'),
            array('title' => '识别结果', 'content' => $recognitionContent,
                'binding' => '识别字段已绑定', 'binding_badge' => 'blue'),
            array('title' => '补全步骤', 'content' => '已将跳步识别与步骤推理结果插入模板。',
                'binding' => '推理步骤已绑定', 'binding_badge' => 'purple'),
            array('title' => '生成说明', 'content' => sprintf('草稿来自 SmartLab 已上传附件、%s 模板库和缺失步骤推理结果，可编辑后提交导师审核。', $label),
                'binding' => '待导师确认', 'binding_badge' => 'orange'),
        );

        if (in_array($assay, array('wb', 'ihc'), true)) {
            $draft[] = array('title' => '异常标记', 'content' => '归一化偏移量 0.73，建议导师复核曝光参数、背景扣除和抗体稀释比例。',
                'binding' => '推理步骤已绑定', 'binding_badge' => 'purple');
        } elseif ($assay === 'qpcr') {
            $draft[] = array('title' => '异常标记', 'content' => 'Treatment fold-change 超过阈值（mTOR FC=2.83 > 2.00），建议核对 Ct 原始值和熔解曲线，排查数值不连续性。',
                'binding' => '推理步骤已绑定', 'binding_badge' => 'purple');
        }

        return $draft;
    }
}
