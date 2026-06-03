<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use DateTimeImmutable;
use Elabftw\Elabftw\Db;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabDashboard;
use Override;
use PDO;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class SmartlabPatentController extends AbstractHtmlController
{
    public function __construct(
        \Elabftw\Elabftw\App $app,
        private readonly string $view,
    ) {
        parent::__construct($app);
    }

    #[Override]
    public function getResponse(): Response
    {
        if ($this->view === 'records'
            && $this->app->Request->isMethod('POST')
            && $this->app->Request->request->getString('smartlab_action') === 'create_experiment'
        ) {
            $experimentId = $this->createExperiment();
            return new RedirectResponse(sprintf('/experiments.php?mode=view&id=%d', $experimentId));
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
        );

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
        $type = $this->escape((string) ($input['experiment_type'] ?? ''));
        $projectCode = $this->escape((string) ($input['project_code'] ?? ''));
        $note = $this->escape((string) ($input['experiment_note'] ?? ''));
        $instrument = $this->escape((string) ($input['instrument_params'] ?? ''));

        $html = '<h2>SmartLab 实验记录</h2>';
        $html .= '<table><tbody>';
        $html .= sprintf('<tr><th>实验类型</th><td>%s</td></tr>', $type ?: '-');
        $html .= sprintf('<tr><th>课题编号</th><td>%s</td></tr>', $projectCode ?: '-');
        $html .= sprintf('<tr><th>仪器参数</th><td>%s</td></tr>', nl2br($instrument) ?: '-');
        $html .= '</tbody></table>';

        $html .= '<h3>样本信息</h3>' . $this->buildRowsTable(
            array('样本编号', '样本名称', '样本类型', '来源', '处理方式', '备注'),
            array(
                $input['sample_code'] ?? array(),
                $input['sample_name'] ?? array(),
                $input['sample_type'] ?? array(),
                $input['sample_source'] ?? array(),
                $input['sample_treatment'] ?? array(),
                $input['sample_note'] ?? array(),
            ),
        );

        $html .= '<h3>试剂耗材</h3>' . $this->buildRowsTable(
            array('名称', '品牌/货号', '规格', '批号', '用量', '单位'),
            array(
                $input['reagent_name'] ?? array(),
                $input['reagent_brand'] ?? array(),
                $input['reagent_spec'] ?? array(),
                $input['reagent_lot'] ?? array(),
                $input['reagent_amount'] ?? array(),
                $input['reagent_unit'] ?? array(),
            ),
        );

        $html .= sprintf('<h3>备注</h3><p>%s</p>', nl2br($note) ?: '无');

        return $html;
    }

    private function buildRowsTable(array $headers, array $columns): string
    {
        $maxRows = 0;
        foreach ($columns as $column) {
            $maxRows = max($maxRows, is_array($column) ? count($column) : 0);
        }
        if ($maxRows === 0) {
            return '<p>未填写。</p>';
        }

        $html = '<table><thead><tr>';
        foreach ($headers as $header) {
            $html .= sprintf('<th>%s</th>', $this->escape($header));
        }
        $html .= '</tr></thead><tbody>';

        for ($i = 0; $i < $maxRows; $i++) {
            $cells = array();
            foreach ($columns as $column) {
                $cells[] = $this->escape((string) ($column[$i] ?? ''));
            }
            if (trim(implode('', $cells)) === '') {
                continue;
            }
            $html .= '<tr>';
            foreach ($cells as $cell) {
                $html .= sprintf('<td>%s</td>', $cell ?: '-');
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function insertExperimentSteps(int $experimentId, array $steps): void
    {
        $Db = Db::getConnection();
        $sql = 'INSERT INTO experiments_steps (item_id, body, ordering) VALUES (:item_id, :body, :ordering)';
        $ordering = 1;
        foreach ($steps as $step) {
            $body = trim((string) $step);
            if ($body === '') {
                continue;
            }
            $req = $Db->prepare($sql);
            $req->bindValue(':item_id', $experimentId, PDO::PARAM_INT);
            $req->bindValue(':body', $this->escape($body));
            $req->bindValue(':ordering', $ordering++, PDO::PARAM_INT);
            $Db->execute($req);
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
