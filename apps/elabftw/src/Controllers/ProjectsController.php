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
use Elabftw\Models\SmartlabReviews;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Override;

final class ProjectsController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canManageProjects($this->app->Users, $portalView)) {
            $this->app->Session->getFlashBag()->add('ko', '项目中心仅在导师端开放。');
            return new RedirectResponse('/dashboard.php');
        }

        if ($this->app->Request->isMethod('POST')) {
            $projectId = $this->app->Request->request->getInt('project_id');
            $action = trim($this->app->Request->request->getString('action'));
            $Projects = new SmartlabProjects($this->app->Users);

            if ($action === 'create_project') {
                $code = trim($this->app->Request->request->getString('code'));
                $name = trim($this->app->Request->request->getString('name'));
                $description = trim($this->app->Request->request->getString('description'));

                if ($code === '' || $name === '') {
                    $this->app->Session->getFlashBag()->add('ko', '项目编号和项目名称不能为空。');
                    return new RedirectResponse('/projects.php');
                }

                $existingProjectId = $Projects->findByCode($code);
                if ($existingProjectId !== null) {
                    $this->app->Session->getFlashBag()->add('ko', '当前团队已存在相同项目编号。');
                    return new RedirectResponse('/projects.php?id=' . $existingProjectId);
                }

                $createdProjectId = $Projects->createForCurrentUser($code, $name, $description);
                $this->app->Session->getFlashBag()->add('ok', '项目已创建。');
                return new RedirectResponse('/projects.php?id=' . $createdProjectId);
            }

            if ($projectId > 0) {
                if ($action === 'add_entity') {
                    $entityType = trim($this->app->Request->request->getString('entity_type'));
                    $entityId = $this->app->Request->request->getInt('entity_id');
                    $Projects->addEntity($projectId, $entityType, $entityId);
                    $this->app->Session->getFlashBag()->add('ok', '项目关联已更新。');
                }

                if ($action === 'remove_entity') {
                    $linkId = $this->app->Request->request->getInt('link_id');
                    $Projects->removeEntity($projectId, $linkId);
                    $this->app->Session->getFlashBag()->add('ok', '已解除项目关联。');
                }

                if ($action === 'review_decision') {
                    $flowId = $this->app->Request->request->getInt('flow_id');
                    $decision = trim($this->app->Request->request->getString('decision'));
                    $comment = trim($this->app->Request->request->getString('comment'));
                    if ($flowId > 0 && !empty($decision)) {
                        (new SmartlabReviews($this->app->Users))->applyDecision($flowId, $decision, $comment);
                        $this->app->Session->getFlashBag()->add('ok', '审核结果已保存到项目工作流。');
                    }
                }

                if ($action === 'experiment_report_feedback') {
                    $materialId = $this->app->Request->request->getInt('material_id');
                    $feedbackAction = trim($this->app->Request->request->getString('feedback_action'));
                    $feedback = trim($this->app->Request->request->getString('feedback'));
                    if ($materialId > 0 && $feedbackAction !== '') {
                        $Projects->saveExperimentReportFeedback($projectId, $materialId, $feedbackAction, $feedback);
                        $this->app->Session->getFlashBag()->add('ok', '导师反馈已保存，并同步到项目审核流。');
                    }
                }
            }

            return new RedirectResponse('/projects.php?id=' . $projectId);
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'projects.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '项目';
    }

    #[Override]
    protected function getData(): array
    {
        $projectId = $this->app->Request->query->getInt('id');
        $Projects = new SmartlabProjects($this->app->Users);
        $data = array(
            'projectsArr' => $Projects->readAll(),
            'activeProject' => null,
            'projectMembersArr' => array(),
            'projectSamplesArr' => array(),
            'projectExperimentsArr' => array(),
            'projectEntitiesArr' => array(),
            'projectReviewsArr' => array(),
            'projectReportMaterialsArr' => array(),
            'projectActivityArr' => array(),
            'availableExperimentsArr' => array(),
            'availableItemsArr' => array(),
        );

        if ($projectId > 0) {
            try {
                $data['activeProject'] = $Projects->readOne($projectId);
                $data['projectMembersArr'] = $Projects->readMembers($projectId);
                $data['projectSamplesArr'] = $Projects->readSamples($projectId);
                $data['projectExperimentsArr'] = $Projects->readExperiments($projectId);
                $data['projectEntitiesArr'] = $Projects->readEntities($projectId);
                $data['projectReviewsArr'] = $Projects->readReviewFlows($projectId);
                $data['projectReportMaterialsArr'] = $Projects->readExperimentReportMaterials($projectId);
                $data['projectActivityArr'] = $Projects->readRecentActivity($projectId);
                $data['availableExperimentsArr'] = $Projects->readAvailableExperiments($projectId);
                $data['availableItemsArr'] = $Projects->readAvailableItems($projectId);
            } catch (ResourceNotFoundException) {
                $this->app->Session->getFlashBag()->add('ko', '当前团队中找不到该项目。');
            }
        }

        return array_merge(parent::getData(), array(
            'projectsArr' => $data['projectsArr'],
            'activeProject' => $data['activeProject'],
            'projectMembersArr' => $data['projectMembersArr'],
            'projectSamplesArr' => $data['projectSamplesArr'],
            'projectExperimentsArr' => $data['projectExperimentsArr'],
            'projectEntitiesArr' => $data['projectEntitiesArr'],
            'projectReviewsArr' => $data['projectReviewsArr'],
            'projectReportMaterialsArr' => $data['projectReportMaterialsArr'],
            'projectActivityArr' => $data['projectActivityArr'],
            'availableExperimentsArr' => $data['availableExperimentsArr'],
            'availableItemsArr' => $data['availableItemsArr'],
        ));
    }
}
