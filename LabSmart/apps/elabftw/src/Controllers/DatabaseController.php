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
use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Models\Config;
use Elabftw\Models\Items;
use Elabftw\Models\ItemsTypes;
use Elabftw\Models\SmartlabItems;
use Elabftw\Models\StorageUnits;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Override;

/**
 * For database.php
 */
final class DatabaseController extends AbstractEntityController
{
    public function __construct(App $app, Items|ItemsTypes $entity)
    {
        parent::__construct($app, $entity);

        $this->categoryArr = $app->itemsCategoryArr;
        $this->statusArr = $this->itemsStatusArr;
    }

    #[Override]
    protected function getPageTitle(): string
    {
        if ($this->Entity instanceof Items) {
            return '资源';
        }
        return _('Resources templates');
    }

    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->App->Request, $this->App->Session, $this->App->Users);
        if ($this->Entity instanceof Items
            && $this->App->Request->query->getAlpha('mode') === 'edit'
            && !SmartlabPortalView::canManageResources($this->App->Users, $portalView)) {
            $this->App->Session->getFlashBag()->add('ko', '学生端和导师端仅查看资源详情、预约信息和库存概况。');
            return new RedirectResponse(sprintf('/database.php?mode=view&id=%d', (int) $this->Entity->id));
        }

        if ($this->Entity instanceof Items
            && $this->App->Request->isMethod('POST')
            && $this->App->Request->request->has('smartlab_action')) {
            if (!SmartlabPortalView::canManageProjects($this->App->Users, $portalView)) {
                $this->App->Session->getFlashBag()->add('ko', '资源项目归属仅由导师端维护。');
                return new RedirectResponse(sprintf('/database.php?mode=view&id=%d', (int) $this->Entity->id));
            }
            return $this->handleSmartlabPost();
        }

        return parent::getResponse();
    }

    #[Override]
    protected function view(): Response
    {
        if (!$this->Entity instanceof Items) {
            return parent::view();
        }

        $RequestActions = new \Elabftw\Models\RequestActions($this->App->Users, $this->Entity);
        $ProcurementRequests = new \Elabftw\Models\ProcurementRequests($this->App->Teams);
        $SmartlabItems = new SmartlabItems($this->App->Users);

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
            'smartlabProjectsArr' => $SmartlabItems->readProjects($this->Entity->id ?? 0),
            'smartlabAvailableProjectsArr' => $SmartlabItems->readAvailableProjects($this->Entity->id ?? 0),
            'smartlabItemExperimentsArr' => $SmartlabItems->readLinkedExperiments($this->Entity->id ?? 0),
            'smartlabItemStorageSummary' => $SmartlabItems->readStorageSummary($this->Entity->id ?? 0),
        );

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render('view.html', $renderArr));

        return $Response;
    }

    private function handleSmartlabPost(): Response
    {
        $itemId = (int) ($this->Entity->id ?? 0);
        $action = trim($this->App->Request->request->getString('smartlab_action'));
        $SmartlabItems = new SmartlabItems($this->App->Users);

        if ($action === 'link_project') {
            $projectId = $this->App->Request->request->getInt('project_id');
            if ($projectId > 0) {
                $SmartlabItems->linkProject($itemId, $projectId);
                $this->App->Session->getFlashBag()->add('ok', '资源已关联到项目。');
            }
        }

        if ($action === 'unlink_project') {
            $linkId = $this->App->Request->request->getInt('link_id');
            if ($linkId > 0) {
                $SmartlabItems->unlinkProject($itemId, $linkId);
                $this->App->Session->getFlashBag()->add('ok', '资源已从项目中移除。');
            }
        }

        return new RedirectResponse(sprintf('/database.php?mode=view&id=%d', $itemId));
    }
}
