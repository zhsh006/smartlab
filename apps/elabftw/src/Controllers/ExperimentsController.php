<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\App;
use Elabftw\Elabftw\PermissionsHelper;
use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Models\Experiments;
use Elabftw\Models\Config;
use Elabftw\Models\ExtraFieldsKeys;
use Elabftw\Models\FavTags;
use Elabftw\Models\ProcurementRequests;
use Elabftw\Models\RequestActions;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\StorageUnits;
use Elabftw\Models\TeamTags;
use Elabftw\Models\UserRequestActions;
use Elabftw\Models\Templates;
use Elabftw\Params\DisplayParams;
use Elabftw\Params\BaseQueryParams;
use Elabftw\Enums\Orderby;
use Elabftw\Enums\Sort;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\InputBag;
use Override;

/**
 * For experiments.php
 */
final class ExperimentsController extends AbstractEntityController
{
    public function __construct(App $app, Experiments | Templates $entity)
    {
        parent::__construct($app, $entity);

        $this->categoryArr = $app->experimentsCategoryArr;
        $this->statusArr = $this->experimentsStatusArr;
    }

    #[Override]
    public function getResponse(): Response
    {
        if ($this->Entity instanceof Experiments
            && $this->App->Request->query->getAlpha('mode') === 'edit'
            && !$this->isStudentExperimentOperator()) {
            $this->App->Session->getFlashBag()->add('ko', '助理端和导师端仅查看实验记录与分析结果。');
            return new RedirectResponse(sprintf('/experiments.php?mode=view&id=%d', (int) $this->Entity->id));
        }

        if ($this->Entity instanceof Experiments
            && $this->App->Request->isMethod('POST')
            && $this->App->Request->request->has('smartlab_action')) {
            if (!$this->isStudentExperimentOperator()) {
                $this->App->Session->getFlashBag()->add('ko', '助理端和导师端不执行实验录入或提交流程。');
                return new RedirectResponse(sprintf('/experiments.php?mode=view&id=%d', (int) $this->Entity->id));
            }
            return $this->handleSmartlabPost();
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getPageTitle(): string
    {
        if ($this->Entity instanceof Experiments) {
            return '实验';
        }
        return '实验模板';
    }

    #[Override]
    public function show(): Response
    {
        if (!$this->Entity instanceof Experiments) {
            return parent::show();
        }

        $TeamTags = new TeamTags($this->App->Users, $this->App->Users->userData['team']);
        $ExtraFieldsKeys = new ExtraFieldsKeys($this->App->Users, '', -1);

        if ($this->App->Session->get('is_anon')) {
            $this->Entity->isAnon = true;
        }

        if (($this->App->Users->userData['always_show_owned'] ?? null) === 1) {
            $this->Entity->alwaysShowOwned = true;
        }

        $orderBy = Orderby::tryFrom($this->App->Users->userData['orderby']) ?? Orderby::Lastchange;
        $skipOrderPinned = $this->App->Request->query->getBoolean('skip_pinned');
        $DisplayParams = new DisplayParams(
            requester: $this->App->Users,
            entityType: $this->Entity->entityType,
            query: $this->App->Request->query,
            orderby: $orderBy,
            sort: Sort::tryFrom($this->App->Users->userData['sort']) ?? Sort::Desc,
            limit: $this->App->Users->userData['limit_nb'],
            skipOrderPinned: $skipOrderPinned,
        );
        $itemsArr = $this->Entity->readShow($DisplayParams);

        $tagsArr = array();
        if (!empty($itemsArr)) {
            $tagsArr = $this->Entity->getTags($itemsArr);
        }

        $this->App->Session->set('lastquery', $this->App->Request->getQueryString());

        $FavTags = new FavTags($this->App->Users);
        $favTagsArr = $FavTags->readAll();
        $UserRequestActions = new UserRequestActions($this->App->Users);
        $SmartlabExperiments = new SmartlabExperiments($this->App->Users);
        $smartlabShowSummaryMap = $SmartlabExperiments->readStatusMap(array_map(static fn(array $item): int => (int) $item['id'], $itemsArr));

        $renderArr = array(
            'DisplayParams' => $DisplayParams,
            'Entity' => $this->Entity,
            'categoryArr' => $this->categoryArr,
            'statusArr' => $this->statusArr,
            'favTagsArr' => $favTagsArr,
            'itemsArr' => $itemsArr,
            'pageTitle' => $this->getPageTitle(),
            'metakeyArrForSelect' => array_column($ExtraFieldsKeys->readAll(), 'extra_fields_key'),
            'requestActionsArr' => $UserRequestActions->readAllFull(),
            'scopedTeamgroupsArr' => $this->scopedTeamgroupsArr,
            'tagsArr' => $tagsArr,
            'tagsArrForSelect' => $TeamTags->readAll(),
            'usersArr' => $this->App->Users->readAllFromTeam(),
            'visibilityArr' => $this->visibilityArr,
            'smartlabShowSummaryMap' => $smartlabShowSummaryMap,
        );

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render('show.html', $renderArr));

        return $Response;
    }

    #[Override]
    protected function view(): Response
    {
        if (!$this->Entity instanceof Experiments) {
            return parent::view();
        }

        $SmartlabExperiments = new SmartlabExperiments($this->App->Users);
        $RequestActions = new RequestActions($this->App->Users, $this->Entity);
        $ProcurementRequests = new ProcurementRequests($this->App->Teams);
        $renderArr = array(
            'categoryArr' => $this->categoryArr,
            'classificationArr' => $this->classificationArr,
            'currencyArr' => $this->currencyArr,
            'Entity' => $this->Entity,
            'entityProcurementRequestsArr' => $ProcurementRequests->readActiveForEntity($this->Entity->id ?? 0),
            'entityRequestActionsArr' => $RequestActions->readAllFull(),
            'pageTitle' => $this->getPageTitle(),
            'mode' => 'view',
            'hideTitle' => true,
            'teamsArr' => $this->App->Teams->readAllVisible(),
            'scopedTeamgroupsArr' => $this->scopedTeamgroupsArr,
            'timestamperFullname' => $this->Entity->getTimestamperFullname(),
            'lockerFullname' => $this->Entity->getLockerFullname(),
            'meaningArr' => $this->meaningArr,
            'requestableActionArr' => $this->requestableActionArr,
            'storageUnitsArr' => (new StorageUnits($this->App->Users, Config::getConfig()->configArr['inventory_require_edit_rights'] === '1'))->readAllRecursive(),
            'usersArr' => $this->App->Users->readAllActiveFromTeam(),
            'visibilityArr' => $this->visibilityArr,
            'smartlabProjectsArr' => $SmartlabExperiments->readProjects($this->Entity->id ?? 0),
            'smartlabAvailableProjectsArr' => $SmartlabExperiments->readAvailableProjects($this->Entity->id ?? 0),
            'smartlabReviewFlowsArr' => $SmartlabExperiments->readReviewFlows($this->Entity->id ?? 0),
            'smartlabSamplesArr' => $SmartlabExperiments->readSamples($this->Entity->id ?? 0),
            'smartlabAvailableSamplesArr' => $SmartlabExperiments->readAvailableSamples($this->Entity->id ?? 0),
            'smartlabLotsArr' => $SmartlabExperiments->readLots($this->Entity->id ?? 0),
            'smartlabAvailableLotsArr' => $SmartlabExperiments->readAvailableLots(),
            'smartlabAssayPackArr' => $SmartlabExperiments->readAssayPack($this->Entity->id ?? 0),
            'smartlabAvailableAssayPacksArr' => $SmartlabExperiments->getAvailableAssayPacks(),
            'smartlabAssayPresetCatalogArr' => $SmartlabExperiments->getAssayPresetCatalog(),
            'smartlabAssayObjectCatalogArr' => $SmartlabExperiments->getAssayObjectCatalog(),
        );

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render('view.html', $renderArr));
        return $Response;
    }

    private function handleSmartlabPost(): Response
    {
        $experimentId = (int) ($this->Entity->id ?? 0);
        $action = trim($this->App->Request->request->getString('smartlab_action'));
        $SmartlabExperiments = new SmartlabExperiments($this->App->Users);

        if ($action === 'link_project') {
            $projectId = $this->App->Request->request->getInt('project_id');
            if ($projectId > 0) {
                $SmartlabExperiments->linkProject($experimentId, $projectId);
                $this->App->Session->getFlashBag()->add('ok', '实验已关联到项目。');
            }
        }

        if ($action === 'unlink_project') {
            $linkId = $this->App->Request->request->getInt('link_id');
            if ($linkId > 0) {
                $SmartlabExperiments->unlinkProject($experimentId, $linkId);
                $this->App->Session->getFlashBag()->add('ok', '实验已从项目中移除。');
            }
        }

        if ($action === 'submit_review') {
            $projectId = $this->App->Request->request->getInt('project_id');
            $comment = trim($this->App->Request->request->getString('review_comment'));
            $reportTitle = trim($this->App->Request->request->getString('report_title'));
            $studentSummary = trim($this->App->Request->request->getString('student_summary'));
            $discussionPoints = trim($this->App->Request->request->getString('discussion_points'));
            $keyUploadIds = array_map(
                static fn($uploadId): int => (int) $uploadId,
                $this->App->Request->request->all('key_upload_ids'),
            );
            if ($projectId > 0) {
                $workflow = $SmartlabExperiments->readAssayPack($experimentId)['workflow'] ?? array();
                if (!(bool) ($workflow['can_submit_review'] ?? false)) {
                    $this->App->Session->getFlashBag()->add('ko', '请先完成实验计划、样本材料确认、原始数据上传和结果确认，再提交导师审核。');
                    return new RedirectResponse(sprintf('/experiments.php?mode=view&id=%d', $experimentId));
                }
                $SmartlabExperiments->submitReview($experimentId, $projectId, $comment, $reportTitle, $studentSummary, $discussionPoints, $keyUploadIds);
                $this->App->Session->getFlashBag()->add('ok', '实验已生成汇报材料，并提交到导师端。');
            }
        }

        if ($action === 'link_sample') {
            $sampleId = $this->App->Request->request->getInt('sample_id');
            $eventType = trim($this->App->Request->request->getString('sample_event_type'));
            $note = trim($this->App->Request->request->getString('sample_note'));
            if ($sampleId > 0) {
                $SmartlabExperiments->linkSample($experimentId, $sampleId, $eventType, $note);
                $this->App->Session->getFlashBag()->add('ok', '实验已关联样本，并写入样本事件流。');
            }
        }

        if ($action === 'record_usage') {
            $usageLotIds = array_map(static fn($lotId): int => (int) $lotId, $this->App->Request->request->all('usage_lot_ids'));
            $legacyUsageLotId = $this->App->Request->request->getInt('usage_lot_id');
            if ($legacyUsageLotId > 0) {
                $usageLotIds[] = $legacyUsageLotId;
            }
            $usageLotIds = array_values(array_unique(array_filter($usageLotIds, static fn(int $lotId): bool => $lotId > 0)));

            $usageType = trim($this->App->Request->request->getString('usage_type'));
            $materialLabel = trim($this->App->Request->request->getString('usage_material_label'));
            $brand = trim($this->App->Request->request->getString('usage_brand'));
            $catalogNo = trim($this->App->Request->request->getString('usage_catalog_no'));
            $lotNo = trim($this->App->Request->request->getString('usage_lot_no'));
            $locationLabel = trim($this->App->Request->request->getString('usage_location_label'));
            $quantity = (float) $this->App->Request->request->get('usage_quantity');
            $unit = trim($this->App->Request->request->getString('usage_unit'));
            $purpose = trim($this->App->Request->request->getString('usage_purpose'));
            $note = trim($this->App->Request->request->getString('usage_note'));

            if ($usageLotIds === array() && $materialLabel === '') {
                $this->App->Session->getFlashBag()->add('ko', '请填写名称，或从库存物料列表中选择物料。');
                return new RedirectResponse(sprintf('/experiments.php?mode=view&id=%d', $experimentId));
            }

            if ($usageLotIds !== array()) {
                foreach ($usageLotIds as $usageLotId) {
                    $SmartlabExperiments->recordUsage(
                        $experimentId,
                        $usageLotId,
                        'inventory',
                        '',
                        $usageType,
                        '',
                        '',
                        '',
                        '',
                        '',
                        $quantity,
                        $unit,
                        $purpose,
                        $note,
                    );
                }
                $this->App->Session->getFlashBag()->add('ok', sprintf('本次使用记录已保存，共 %d 条。', count($usageLotIds)));
            } else {
                $SmartlabExperiments->recordUsage(
                    $experimentId,
                    0,
                    'manual',
                    '',
                    $usageType,
                    $materialLabel,
                    $brand,
                    $catalogNo,
                    $lotNo,
                    $locationLabel,
                    $quantity,
                    $unit,
                    $purpose,
                    $note,
                );
                $this->App->Session->getFlashBag()->add('ok', '本次使用记录已保存。');
            }
        }

        if ($action === 'save_assay_pack') {
            $SmartlabExperiments->saveAssayPack($experimentId, $this->App->Request->request->all());
            $this->App->Session->getFlashBag()->add('ok', 'Assay Pack 已保存，结构化结果和质控摘要已更新。');
        }

        return new RedirectResponse(sprintf('/experiments.php?mode=view&id=%d', $experimentId));
    }

    private function isStudentExperimentOperator(): bool
    {
        return SmartlabPortalView::resolve($this->App->Request, $this->App->Session, $this->App->Users) === SmartlabPortalView::Student;
    }
}
