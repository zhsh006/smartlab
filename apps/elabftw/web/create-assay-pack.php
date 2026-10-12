<?php

declare(strict_types=1);

namespace Elabftw\Elabftw;

use DateTimeImmutable;
use Elabftw\Enums\BasePermissions;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabExperiments;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

require_once 'app/init.inc.php';
$Response = new Response();

try {
    $pack = $Request->request->getString('pack') ?: $Request->query->getString('pack');
    $mode = 'quick';
    $packLabels = array(
        'qpcr' => 'qPCR Assay Pack',
        'western_blot' => 'Western Blot Assay Pack',
        'if' => 'Immunofluorescence Assay Pack',
        'ihc' => 'Immunohistochemistry Assay Pack',
        'elisa' => 'ELISA Assay Pack',
    );

    if (!array_key_exists($pack, $packLabels)) {
        throw new AppException('Unsupported assay pack.');
    }

    $SmartlabExperiments = new SmartlabExperiments($App->Users);
    $projectCode = trim($Request->request->getString('project_code'));
    $targetName = trim($Request->request->getString('target_name'));
    $experimentCode = $SmartlabExperiments->generateExperimentCode($pack);
    $bodyParts = array(
        sprintf('<p><strong>实验编号：</strong>%s</p>', htmlspecialchars($experimentCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
    );
    if ($projectCode !== '') {
        $bodyParts[] = sprintf('<p><strong>课题编号：</strong>%s</p>', htmlspecialchars($projectCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    if ($targetName !== '') {
        $bodyParts[] = sprintf('<p><strong>检测指标：</strong>%s</p>', htmlspecialchars($targetName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    $metadata = json_encode(array_filter(array(
        'experiment_code' => $experimentCode,
        'pack_key' => $pack,
        'project_code' => $projectCode,
        'target_name' => $targetName,
    )), JSON_THROW_ON_ERROR);

    $Experiments = new Experiments($App->Users);
    $title = $targetName !== ''
        ? sprintf('%s · %s · %s', $packLabels[$pack], $targetName, (new DateTimeImmutable())->format('Y-m-d'))
        : sprintf('%s · %s', $packLabels[$pack], (new DateTimeImmutable())->format('Y-m-d'));
    $newId = $Experiments->create(
        title: $title,
        body: implode("\n", $bodyParts),
        canreadBase: BasePermissions::User,
        canwriteBase: BasePermissions::User,
        metadata: $metadata,
    );

    if ($projectCode !== '') {
        $projectId = $SmartlabExperiments->findProjectIdByCode($projectCode);
        if ($projectId !== null) {
            $SmartlabExperiments->linkProject($newId, $projectId);
        }
    }

    $SmartlabExperiments->initializeAssayPack($newId, $pack, $mode, array(
        'project_code' => $projectCode,
        'target_name' => $targetName,
    ));

    $App->Session->getFlashBag()->add('ok', $mode === 'quick'
        ? sprintf('%s 已按快速模式创建，实验编号：%s。', $packLabels[$pack], $experimentCode)
        : sprintf('%s 已按标准模式创建，实验编号：%s。', $packLabels[$pack], $experimentCode));

    $Response = new RedirectResponse(sprintf('/experiments.php?mode=view&id=%d#experiment-assay-pack', $newId));
} catch (AppException $e) {
    $Response = $e->getResponseFromException($App);
} catch (Exception $e) {
    $Response = $App->getResponseFromException($e);
} finally {
    $Response->send();
}
