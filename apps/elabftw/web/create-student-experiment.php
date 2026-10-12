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
    $portalView = SmartlabPortalView::resolve($Request, $App->Session, $App->Users);
    if (!SmartlabPortalView::canOperateExperiments($App->Users, $portalView)) {
        throw new AppException('Only students can create student experiments.');
    }

    $title = trim($Request->request->getString('title'));
    if ($title === '') {
        $title = '空白实验 · ' . (new DateTimeImmutable())->format('Y-m-d');
    }

    $newId = (new Experiments($App->Users))->create(
        title: $title,
        canreadBase: BasePermissions::User,
        canwriteBase: BasePermissions::User,
    );

    $App->Session->getFlashBag()->add('ok', '空白实验已创建。');
    $Response = new RedirectResponse(sprintf('/experiments.php?mode=edit&id=%d', $newId));
} catch (AppException $e) {
    $Response = $e->getResponseFromException($App);
} catch (Exception $e) {
    $Response = $App->getResponseFromException($e);
} finally {
    $Response->send();
}
