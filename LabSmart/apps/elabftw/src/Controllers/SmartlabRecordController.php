<?php

/**
 * New SmartLab quick record page: simplified recording view on top of the bench records model.
 *
 * @author SmartLab
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Enums\AccessType;
use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\SmartlabWbConditions;
use Elabftw\Services\SmartlabAssayWorkspace;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

use function count;
use function is_array;

final class SmartlabRecordController extends AbstractHtmlController
{
    public function getResponse(): Response
    {
        if ($this->app->Request->isMethod('POST')) {
            if (SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users) !== SmartlabPortalView::Student) {
                return new JsonResponse(array('ok' => false, 'message' => '导师端和入库人员仅查看实验记录，不能代填本次条件。'), 422);
            }
            $bench = new SmartlabBenchRecords($this->app->Users);
            $id = $this->app->Request->query->getInt('id');
            try {
                $input = $this->app->Request->request->all();
                if ($this->app->Request->request->getString('smartlab_action') === 'assay_csv_preview') { return new JsonResponse(array('ok' => true, 'preview' => $bench->previewAssayCsv($id, $input))); }
                $record = match ($this->app->Request->request->getString('smartlab_action')) {
                    'wb_setup' => $bench->saveWbSetup($id, $input),
                    'wb_actual' => $bench->saveWbActual($id, $input),
                    'wb_progress' => $bench->saveWbProgress($id, $input),
                    'assay_workspace' => $bench->saveAssayWorkspace($id, $input),
                    'assay_note' => $bench->saveAssayNote($id, $input),
                    'assay_file' => $bench->saveAssayFile($id, $input),
                    'assay_csv_save' => $bench->saveAssayCsv($id, $input),
                    default => throw new AppException('不支持的记录操作。'),
                };
                return new JsonResponse(array('ok' => true, 'revision' => (int) $record['revision'], 'state' => $record['data']['state'], 'loading_calculation' => $record['data']['loading_calculation'] ?? array()));
            } catch (AppException $error) { return new JsonResponse(array('ok' => false, 'message' => $error->getMessage()), 422); }
        }
        return parent::getResponse();
    }
    protected function getTemplate(): string
    {
        return 'smartlab-record.html';
    }

    protected function getPageTitle(): string
    {
        return '实验快速记录';
    }

    protected function getData(): array
    {
        $id = $this->app->Request->query->getInt('id');
        if ($id <= 0) {
            throw new AppException('缺少实验编号，请从实验工作台进入。');
        }
        $Experiments = new Experiments($this->app->Users, $id);
        $Experiments->readOne();
        $Experiments->canOrExplode(AccessType::Read);

        $SmartlabExperiments = new SmartlabExperiments($this->app->Users);
        $Bench = new SmartlabBenchRecords($this->app->Users);
        $bench = $Bench->read($id);
        $wb = $bench['template_key'] === 'western_blot' ? new SmartlabWbConditions($this->app->Users) : null;

        $projectLabel = '';
        foreach ($SmartlabExperiments->readProjects($id) as $project) {
            $projectLabel = trim((string) ($project['code'] ?? '') . ' · ' . (string) ($project['name'] ?? ''), ' ·');
            break;
        }
        $meta = json_decode((string) ($Experiments->entityData['metadata'] ?? ''), true);
        $code = is_array($meta) ? (string) ($meta['experiment_code'] ?? ($meta['code'] ?? '')) : '';

        $progress = SmartlabBenchRecords::progress($bench['template'], $bench['records']);
        $currentStepIndex = $progress['resume_index'];

        return array_merge(parent::getData(), array(
            'Entity' => $Experiments,
            'bench' => $bench,
            'assayView' => in_array($bench['template_key'], array('qpcr', 'if'), true) ? SmartlabAssayWorkspace::view($bench, $this->app->Request->query->getInt('file')) : null,
            'assayCanEdit' => SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users) === SmartlabPortalView::Student,
            'wbView' => $wb ? $Bench->wbView($bench) : null,
            'wbProfiles' => $wb ? $wb->profiles() : array(),
            'wbMethods' => $wb ? $wb->methods() : array(),
            'wbCatalogToken' => $wb ? $wb->catalogToken() : '',
            'experimentCode' => $code,
            'projectLabel' => $projectLabel,
            'currentStepIndex' => $currentStepIndex,
            'experimentProgress' => $progress,
            'historyView' => $this->buildHistoryView(array_slice($bench['history'], 0, 12), $bench['template']),
            'historyFullView' => $this->buildHistoryView($bench['history'], $bench['template']),
            'smartlabAvailableSamplesArr' => $SmartlabExperiments->readAvailableSamples($id),
            'smartlabAvailableLotsArr' => $SmartlabExperiments->readAvailableLots(),
            'smartlabAssayPresetCatalogArr' => $SmartlabExperiments->getAssayPresetCatalog(),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function buildHistoryView(array $events, array $template): array
    {
        $view = array();
        foreach ($events as $event) {
            if (str_starts_with((string) $event['record_key'], 'submission.')) {
                continue;
            }
            $changes = array();
            foreach (array_slice($event['changes'] ?? array(), 0, 3) as $change) {
                $changes[] = array(
                    'field' => (string) $change['field'],
                    'before' => is_array($change['before']) ? '' : (string) $change['before'],
                    'after' => is_array($change['after']) ? '' : (string) $change['after'],
                );
            }
            $view[] = array(
                'label' => SmartlabBenchRecords::describeRecordKey((string) $event['record_key'], $template),
                'type' => (string) $event['event_type'],
                'state' => (string) ($event['data']['state'] ?? ''),
                'time' => self::formatTime((string) $event['recorded_at']),
                'actor' => (string) ($event['actor_name'] ?? ''),
                'mode' => (string) $event['entry_mode'],
                'reason' => (string) ($event['data']['entry_reason'] ?? ''),
                'changes' => $changes,
            );
        }
        return $view;
    }

    private static function formatTime(string $utc): string
    {
        if ($utc === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('m-d H:i');
        } catch (\Exception) {
            return '';
        }
    }
}
