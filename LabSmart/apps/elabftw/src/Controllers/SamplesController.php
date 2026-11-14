<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2026 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Exceptions\ResourceNotFoundException;
use Elabftw\Models\SmartlabProjects;
use Elabftw\Models\SmartlabSamples;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class SamplesController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if ($this->app->Request->isMethod('POST')
            && $this->app->Request->request->has('smartlab_action')) {
            $action = trim($this->app->Request->request->getString('smartlab_action'));
            $Samples = new SmartlabSamples($this->app->Users);

            if ($action === 'create_sample' && SmartlabPortalView::canOperateExperiments($this->app->Users, $portalView)) {
                $code = trim($this->app->Request->request->getString('code'));
                $name = trim($this->app->Request->request->getString('name'));
                $sampleType = trim($this->app->Request->request->getString('sample_type'));
                $projectId = $this->app->Request->request->getInt('project_id') ?: null;
                $quantityRaw = trim((string) $this->app->Request->request->get('quantity', ''));
                $quantity = $quantityRaw !== '' ? (float) $quantityRaw : null;
                $unit = trim($this->app->Request->request->getString('unit'));
                $metadata = array_filter(array(
                    'source' => trim($this->app->Request->request->getString('source')),
                    'storage_location' => trim($this->app->Request->request->getString('storage_location')),
                    'freezer_box' => trim($this->app->Request->request->getString('freezer_box')),
                    'well_position' => trim($this->app->Request->request->getString('well_position')),
                    'note' => trim($this->app->Request->request->getString('note')),
                ), static fn(string $value): bool => $value !== '');

                if ($code !== '' && $name !== '' && $sampleType !== '') {
                    $sampleId = $Samples->create($code, $name, $sampleType, $projectId, $quantity, $unit, $metadata);
                    $this->app->Session->getFlashBag()->add('ok', '样本已创建。');
                    return new RedirectResponse('/samples.php?id=' . $sampleId);
                }

                $this->app->Session->getFlashBag()->add('ko', '样本编号、名称和类型不能为空。');
            }

            if ($action === 'add_sample_event' && SmartlabPortalView::canOperateExperiments($this->app->Users, $portalView)) {
                $sampleId = $this->app->Request->request->getInt('sample_id');
                $eventType = trim($this->app->Request->request->getString('event_type'));
                $experimentId = $this->app->Request->request->getInt('experiment_id') ?: null;
                $quantityRaw = trim((string) $this->app->Request->request->get('quantity_delta', ''));
                $quantityDelta = $quantityRaw !== '' ? (float) $quantityRaw : null;
                $note = trim($this->app->Request->request->getString('note'));

                if ($sampleId > 0 && $eventType !== '') {
                    $Samples->addEvent($sampleId, $eventType, $experimentId, $quantityDelta, $note);
                    $this->app->Session->getFlashBag()->add('ok', '样本事件已记录。');
                }

                return new RedirectResponse('/samples.php?id=' . $sampleId);
            }

            if ($action === 'update_sample' && SmartlabPortalView::canOperateExperiments($this->app->Users, $portalView)) {
                $sampleId = $this->app->Request->request->getInt('sample_id');
                $code = trim($this->app->Request->request->getString('code'));
                $name = trim($this->app->Request->request->getString('name'));
                $sampleType = trim($this->app->Request->request->getString('sample_type'));
                $status = trim($this->app->Request->request->getString('status'));
                $projectId = $this->app->Request->request->getInt('project_id') ?: null;
                $quantityRaw = trim((string) $this->app->Request->request->get('quantity', ''));
                $quantity = $quantityRaw !== '' ? (float) $quantityRaw : null;
                $unit = trim($this->app->Request->request->getString('unit'));
                $metadata = array_filter(array(
                    'source' => trim($this->app->Request->request->getString('source')),
                    'storage_location' => trim($this->app->Request->request->getString('storage_location')),
                    'freezer_box' => trim($this->app->Request->request->getString('freezer_box')),
                    'well_position' => trim($this->app->Request->request->getString('well_position')),
                    'note' => trim($this->app->Request->request->getString('note')),
                ), static fn(string $value): bool => $value !== '');
                $changeNote = trim($this->app->Request->request->getString('change_note'));

                if ($sampleId > 0 && $code !== '' && $name !== '' && $sampleType !== '') {
                    $Samples->update($sampleId, $code, $name, $sampleType, $status, $projectId, $quantity, $unit, $metadata, $changeNote);
                    $this->app->Session->getFlashBag()->add('ok', '样本信息已更新。');
                } else {
                    $this->app->Session->getFlashBag()->add('ko', '样本编号、名称和类型不能为空。');
                }

                return new RedirectResponse('/samples.php?id=' . $sampleId);
            }

            return new RedirectResponse('/samples.php');
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'samples.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '样本';
    }

    #[Override]
    protected function getData(): array
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        $isMentorView = $portalView === SmartlabPortalView::Mentor;
        $sampleId = $this->app->Request->query->getInt('id');
        $sampleStatusFilter = trim($this->app->Request->query->getString('sample_status'));
        $sampleScopeFilter = trim($this->app->Request->query->getString('sample_scope'));
        if (!in_array($sampleStatusFilter, array('', 'active', 'reserved', 'consumed', 'archived', 'discarded'), true)) {
            $sampleStatusFilter = '';
        }
        if (!in_array($sampleScopeFilter, array('all', 'mine', 'my_projects'), true)) {
            $sampleScopeFilter = 'all';
        }
        $sampleProjectRaw = trim($this->app->Request->query->getString('sample_project_id'));
        $sampleProjectFilter = $sampleProjectRaw !== '' ? (int) $sampleProjectRaw : null;
        $Samples = new SmartlabSamples($this->app->Users);
        $data = array(
            'samplesArr' => $Samples->readAll($sampleStatusFilter, $sampleProjectFilter, $sampleScopeFilter),
            'activeSample' => null,
            'sampleEventsArr' => array(),
            'sampleExperimentsArr' => array(),
            'sampleReviewsArr' => array(),
            'sampleStatusFilter' => $sampleStatusFilter,
            'sampleScopeFilter' => $sampleScopeFilter,
            'sampleProjectFilter' => $sampleProjectFilter,
        );

        if ($sampleId > 0) {
            try {
                $data['activeSample'] = $Samples->readOne($sampleId);
                $data['sampleEventsArr'] = $Samples->readEvents($sampleId);
                $data['sampleExperimentsArr'] = $Samples->readLinkedExperiments($sampleId);
                $data['sampleReviewsArr'] = $Samples->readReviewFlows($sampleId);
            } catch (ResourceNotFoundException) {
                $this->app->Session->getFlashBag()->add('ko', '当前团队中找不到该样本。');
            }
        }

        $Projects = new SmartlabProjects($this->app->Users);

        return array_merge(parent::getData(), $data, array(
            'smartlabIsMentorView' => $isMentorView,
            'studentProjectsArr' => SmartlabPortalView::canAccessOperations($this->app->Users, $portalView)
                ? $Projects->readAll()
                : $Projects->readForCurrentUser(),
        ));
    }
}
