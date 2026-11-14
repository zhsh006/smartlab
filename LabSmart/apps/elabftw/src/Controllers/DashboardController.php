<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2023 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Elabftw\Elabftw\PermissionsHelper;
use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Enums\EntityType;
use Elabftw\Enums\Orderby;
use Elabftw\Models\Experiments;
use Elabftw\Models\ExperimentsStatus;
use Elabftw\Models\FavTags;
use Elabftw\Models\Items;
use Elabftw\Models\ItemsStatus;
use Elabftw\Models\ItemsTypes;
use Elabftw\Models\Scheduler;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabDashboard;
use Elabftw\Models\SmartlabProjects;
use Elabftw\Models\Templates;
use Elabftw\Models\UserRequestActions;
use Elabftw\Params\DisplayParams;
use Override;
use Symfony\Component\HttpFoundation\InputBag;

use function array_merge;

/**
 * For dashboard.php
 */
final class DashboardController extends AbstractHtmlController
{
    private const int SHOWN_NUMBER = 6;

    #[Override]
    protected function getTemplate(): string
    {
        return 'dashboard.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '实验记录';
    }

    #[Override]
    protected function getData(): array
    {
        $DisplayParamsExp = new DisplayParams(
            $this->app->Users,
            EntityType::Experiments,
            orderby: Orderby::Lastchange,
            limit: self::SHOWN_NUMBER,
        );
        $Experiments = new Experiments($this->app->Users);
        $Items = new Items($this->app->Users);
        $ItemsTypes = new ItemsTypes($this->app->Users);
        $Templates = new Templates($this->app->Users);
        $now = new DateTimeImmutable();
        $Scheduler = new Scheduler($Items, start: $now->format(DateTimeImmutable::ATOM));
        // for items we need to create a new DisplayParams object, otherwise the scope setting will also apply here
        $DisplayParamsItems = new DisplayParams(
            $this->app->Users,
            EntityType::Items,
            orderby: Orderby::Lastchange,
            limit: self::SHOWN_NUMBER,
        );
        $PermissionsHelper = new PermissionsHelper();
        $ExperimentsStatus = new ExperimentsStatus($this->app->Teams);
        $ItemsStatus = new ItemsStatus($this->app->Teams);
        $UserRequestActions = new UserRequestActions($this->app->Users);

        $DisplayParamsTemplates = new DisplayParams($this->app->Users, EntityType::Templates);
        $DisplayParamsItemsTypes = new DisplayParams($this->app->Users, EntityType::ItemsTypes);

        $FavTags = new FavTags($this->app->Users);
        $favTagsArr = $FavTags->readAll();
        $SmartlabDashboard = new SmartlabDashboard($this->app->Users);
        $isMentorView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users) === SmartlabPortalView::Mentor;

        $page = max(1, $this->app->Request->query->getInt('page', 1));
        $notebookQuery = new InputBag(array('scope' => $isMentorView ? '2' : '1', 'q' => $this->app->Request->query->getString('q'), 'limit' => '51', 'offset' => (string) (($page - 1) * 50)));
        $notebookParams = new DisplayParams($this->app->Users, EntityType::Experiments, query: $notebookQuery, limit: 51);
        $projectId = max(0, $this->app->Request->query->getInt('project'));
        $notebookParams->appendSmartlabProjectFilter($projectId, (int) $this->app->Users->team);
        if ($this->app->Request->query->getString('tab') === 'tasks') {
            $notebookParams->appendSmartlabFeedbackFilter();
        }
        $notebookRows = $Experiments->readShow($notebookParams);
        $notebookHasMore = count($notebookRows) > 50;
        $notebookRows = array_slice($notebookRows, 0, 50);
        $notebookContext = $SmartlabDashboard->readNotebookContext(array_column($notebookRows, 'id'));
        $benchSummaries = self::summarizeBenchRecords($SmartlabDashboard->readBenchSummaries(array_column($notebookRows, 'id')));
        $taskboard = $this->buildTaskboard($notebookRows, $notebookContext, $benchSummaries, $SmartlabDashboard);
        $workbenchRows = array();
        foreach ($notebookRows as $row) {
            $meta = json_decode((string) ($notebookContext[$row['id']]['metadata'] ?? $row['metadata'] ?? ''), true) ?: array();
            $row['experiment_code'] = (string) ($meta['experiment_code'] ?? $meta['code'] ?? ('EXP-' . $row['id']));
            $row['bench'] = $benchSummaries[$row['id']] ?? array();
            $row['context'] = $notebookContext[$row['id']] ?? array();
            $workbenchRows[] = $row;
        }
        usort($workbenchRows, static function (array $a, array $b): int {
            $aProgress = $a['bench']['progress'] ?? array();
            $bProgress = $b['bench']['progress'] ?? array();
            $aRank = !empty($aProgress['finished']) ? 2 : (!empty($aProgress['has_stage']) ? 0 : 1);
            $bRank = !empty($bProgress['finished']) ? 2 : (!empty($bProgress['has_stage']) ? 0 : 1);
            return ($aRank <=> $bRank) ?: strcmp((string) ($b['bench']['last_recorded_at'] ?? $b['date']), (string) ($a['bench']['last_recorded_at'] ?? $a['date']));
        });
        $Projects = new SmartlabProjects($this->app->Users);
        $notebookProjects = $isMentorView ? $Projects->readAll() : $Projects->readForCurrentUser();

        return array_merge(
            parent::getData(),
            array(
                'notebookRows' => $notebookRows,
                'workbenchRows' => $workbenchRows,
                'notebookContext' => $notebookContext,
                'benchSummaries' => $benchSummaries,
                'taskboard' => $taskboard,
                'notebookProjects' => $notebookProjects,
                'notebookPage' => $page,
                'notebookHasMore' => $notebookHasMore,
                'bookingsArr' => $Scheduler->readAll(),
                'itemsStatusArr' => $ItemsStatus->readAll(),
                'experimentsArr' => $Experiments->readShow($DisplayParamsExp),
                'experimentsStatusArr' => $ExperimentsStatus->readAll($ExperimentsStatus->getQueryParams(new InputBag(array('limit' => 9999)))),
                'favTagsArr' => $favTagsArr,
                'itemsArr' => $Items->readShow($DisplayParamsItems),
                'itemsTemplatesArr' => $ItemsTypes->readAllSimple($DisplayParamsItemsTypes),
                'requestActionsArr' => $UserRequestActions->readAllFull(),
                'smartlabOverview' => $SmartlabDashboard->readOverview(),
                'smartlabPendingReviewsArr' => $SmartlabDashboard->readPendingReviews(),
                'smartlabProjectSnapshotsArr' => $SmartlabDashboard->readProjectSnapshots(),
                'smartlabRecentSampleEventsArr' => $SmartlabDashboard->readRecentSampleEvents(),
                'smartlabMyExperimentsArr' => $SmartlabDashboard->readMyExperiments(),
                'smartlabMySamplesArr' => $SmartlabDashboard->readMySamples(),
                'smartlabMyReviewsArr' => $SmartlabDashboard->readMyReviews(),
                'smartlabIsMentorView' => $isMentorView,
                'templatesArr' => $Templates->readAllSimple($DisplayParamsTemplates),
                'usersArr' => $this->app->Users->readAllActiveFromTeam(),
                'visibilityArr' => $PermissionsHelper->getAssociativeArray(),
            ),
        );
    }

    /**
     * V3 “今天做什么” taskboard: today's todos, weekly plan progress and reminders.
     */
    private function buildTaskboard(array $rows, array $contexts, array $benchSummaries, SmartlabDashboard $SmartlabDashboard): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $now = new DateTimeImmutable('now', $timezone);
        $weekStart = $now->modify('monday this week')->format('Y-m-d');
        $weekEnd = $now->modify('sunday this week')->format('Y-m-d');

        $todos = array();
        foreach ($rows as $row) {
            if (count($todos) >= 6) {
                break;
            }
            $id = (int) $row['id'];
            $context = $contexts[$id] ?? array();
            if ((string) ($context['review_status'] ?? '') === 'needs_revision') {
                $todos[] = array('id' => $id, 'title' => (string) $row['title'], 'action' => '补充导师退回的记录', 'chip' => '待补充', 'chip_class' => 'needs_revision');
                continue;
            }
            $summary = $benchSummaries[$id] ?? null;
            if ($summary === null) {
                $todos[] = array('id' => $id, 'title' => (string) $row['title'], 'action' => '开始实验记录', 'chip' => '待开始', 'chip_class' => '');
                continue;
            }
            $stage = (string) ($summary['current_stage'] ?? '');
            if ($stage !== '' && $stage !== '关键步骤已确认') {
                $todos[] = array('id' => $id, 'title' => (string) $row['title'], 'action' => '继续 ' . $stage, 'chip' => '记录中', 'chip_class' => 'submitted');
                continue;
            }
            $lastKey = (string) ($summary['last_key'] ?? '');
            $lastState = (string) ($summary['last_state'] ?? '');
            if ($lastKey === 'result' && $lastState === 'confirmed') {
                $todos[] = array('id' => $id, 'title' => (string) $row['title'], 'action' => '整理结果，准备汇报', 'chip' => '待整理', 'chip_class' => '');
            } elseif ($lastKey === 'result') {
                $todos[] = array('id' => $id, 'title' => (string) $row['title'], 'action' => '确认结果与结论', 'chip' => '待确认', 'chip_class' => '');
            } else {
                $todos[] = array('id' => $id, 'title' => (string) $row['title'], 'action' => '记录结果与结论', 'chip' => '待结果', 'chip_class' => '');
            }
        }

        $userId = (int) $this->app->Users->userid;
        $teamId = (int) $this->app->Users->team;
        $weekTasks = $SmartlabDashboard->readMyWeekTasks($userId, $teamId, $weekStart, $weekEnd);
        $done = 0;
        foreach ($weekTasks as $task) {
            if (($task['status'] ?? '') === 'done') {
                $done++;
            }
        }
        $total = count($weekTasks);

        $reminders = array();
        foreach ($SmartlabDashboard->readExpiringLots($teamId) as $lot) {
            $reminders[] = array(
                'icon' => 'fa-flask',
                'text' => $lot['material_name'] . '（批次 ' . $lot['lot_no'] . '）效期临近',
                'date' => (string) $lot['expires_at'],
                'soon' => ((int) $lot['days_left']) <= 3,
            );
        }
        $soonLimit = $now->modify('+3 day')->format('Y-m-d');
        foreach ($weekTasks as $task) {
            $due = (string) ($task['due_date'] ?? '');
            if ($due !== '' && ($task['status'] ?? '') !== 'done' && $due <= $soonLimit) {
                $reminders[] = array('icon' => 'fa-clipboard-check', 'text' => '任务截止：' . $task['title'], 'date' => $due, 'soon' => true);
            }
        }
        usort($reminders, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return array(
            'todos' => $todos,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'weekTasks' => $weekTasks,
            'weekDone' => $done,
            'weekTotal' => $total,
            'weekPercent' => $total > 0 ? (int) round($done * 100 / $total) : 0,
            'reminders' => array_slice($reminders, 0, 6),
        );
    }

    /** @param array<int, array<string, mixed>> $summaries */
    private static function summarizeBenchRecords(array $summaries): array
    {
        $templates = SmartlabBenchRecords::templates();
        foreach ($summaries as $experimentId => $summary) {
            $template = $templates[$summary['template_key']] ?? array();
            $summaries[$experimentId]['last_label'] = ($summary['last_source'] ?? '') === 'template_created'
                ? '实验模板已建立' : SmartlabBenchRecords::describeRecordKey((string) $summary['last_key'], $template);
            $summaries[$experimentId]['last_time'] = self::formatBenchTime((string) $summary['last_recorded_at']);
            $summaries[$experimentId]['current_stage'] = self::benchCurrentStage($summary, $template);
            $summaries[$experimentId]['template_label'] = $template['label'] ?? '未选择模板';
            $summaries[$experimentId]['progress'] = SmartlabBenchRecords::progress($template, $summary['step_records'] ?? array());
            $prepare = $summary['step_records']['step.prepare']['data']['fields'] ?? array();
            $summaries[$experimentId]['sample_summary'] = (string) ($prepare['groups'] ?? '') ?: (string) ($prepare['sample_label'] ?? '');
            $summaries[$experimentId]['sample_confirmed'] = ($summary['step_records']['step.prepare']['data']['state'] ?? '') === 'confirmed';
        }
        return $summaries;
    }

    private static function benchCurrentStage(array $summary, array $template): string
    {
        $steps = $template['steps'] ?? array();
        if ($steps === array()) { return ''; }
        $progress = SmartlabBenchRecords::progress($template, $summary['step_records'] ?? array());
        return $progress['label'] . ($progress['state'] === 'confirmed' ? ' · 已确认' : ($progress['has_stage'] ? ' · 草稿' : ' · 尚未记录'));
    }

    private static function formatBenchTime(string $utc): string
    {
        if ($utc === '') { return ''; }
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('m-d H:i');
        } catch (\Exception) { return ''; }
    }
}
