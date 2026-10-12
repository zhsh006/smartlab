<?php

declare(strict_types=1);

namespace Elabftw\Elabftw;

use DateTimeImmutable;
use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Enums\BasePermissions;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\Experiments;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

require_once 'app/init.inc.php';

$Response = new Response();

try {
    if (!$Request->isMethod('POST')) {
        throw new AppException('请从实验列表的新建表单创建记录。');
    }
    $portalView = SmartlabPortalView::resolve($Request, $App->Session, $App->Users);
    if (!SmartlabPortalView::canOperateExperiments($App->Users, $portalView)) {
        throw new AppException('Only students can create student experiments.');
    }

    $title = trim($Request->request->getString('title'));
    if ($title === '') {
        $title = '空白实验 · ' . (new DateTimeImmutable())->format('Y-m-d');
    }

    $dateText = $Request->request->getString('date') ?: (new DateTimeImmutable())->format('Y-m-d');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
    if ($date === false || $date->format('Y-m-d') !== $dateText) {
        throw new AppException('请选择有效的实验日期。');
    }
    $projectId = max(0, $Request->request->getInt('project_id'));
    $Projects = new \Elabftw\Models\SmartlabProjects($App->Users);
    if ($projectId > 0) {
        $Projects->readOneForCurrentUser($projectId);
    }
    $packKey = $Request->request->getString('pack_key');
    if (!isset(\Elabftw\Models\SmartlabBenchRecords::templates()[$packKey])) { throw new AppException('请选择实验记录模板。'); }
    $newId = (new Experiments($App->Users))->create(
        title: $title,
        date: $date,
        canreadBase: BasePermissions::User,
        canwriteBase: BasePermissions::User,
    );
    if ($projectId > 0) {
        $Projects->addEntity($projectId, 'experiment', $newId);
    }

    (new \Elabftw\Models\SmartlabExperiments($App->Users))->initializeAssayPack($newId, $packKey);
    $App->Session->getFlashBag()->add('ok', '实验模板已创建，请确认计划并记录实际操作。');
    $Response = new RedirectResponse(sprintf('/experiments.php?mode=view&id=%d#bench-plan', $newId));
} catch (AppException $e) {
    $Response = $e->getResponseFromException($App);
} catch (Exception $e) {
    $Response = $App->getResponseFromException($e);
} finally {
    $Response->send();
}
