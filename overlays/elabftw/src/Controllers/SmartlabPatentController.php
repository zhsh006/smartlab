<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Models\SmartlabDashboard;
use Override;

final class SmartlabPatentController extends AbstractHtmlController
{
    public function __construct(
        \Elabftw\Elabftw\App $app,
        private readonly string $view,
    ) {
        parent::__construct($app);
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
            'templates' => '实验模板补全与过程草稿生成',
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

        return array_merge(parent::getData(), array(
            'smartlabPatentView' => $this->view,
            'smartlabPatentOverview' => $SmartlabDashboard->readOverview(),
            'smartlabPatentExperimentsArr' => $SmartlabDashboard->readMyExperiments(6),
            'smartlabPatentSamplesArr' => $SmartlabDashboard->readMySamples(6),
            'smartlabPatentRecentExperimentsArr' => $SmartlabDashboard->readRecentExperiments(6),
            'smartlabPatentAlertsArr' => $SmartlabDashboard->readOverviewAlerts(3),
            'smartlabPatentUserName' => $displayName !== '' ? $displayName : ($userData['email'] ?? 'SmartLab User'),
            'smartlabPatentToday' => $now->format('Y-m-d'),
            'smartlabPatentWeekday' => '星期' . $weekday,
        ));
    }
}
