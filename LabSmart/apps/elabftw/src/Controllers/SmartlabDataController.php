<?php

/**
 * Data page: instrument data package inbox with manual assignment (V3).
 *
 * @author SmartLab
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Exceptions\AppException;
use Elabftw\Models\SmartlabDataCenter;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class SmartlabDataController extends AbstractHtmlController
{
    protected function getTemplate(): string
    {
        return 'smartlab-data.html';
    }

    protected function getPageTitle(): string
    {
        return '数据';
    }

    #[Override]
    public function getResponse(): Response
    {
        if ($this->app->Request->isMethod('POST')) {
            $Center = new SmartlabDataCenter($this->app->Users);
            $action = $this->app->Request->request->getString('action');
            try {
                if ($action === 'link_package') {
                    $Center->linkPackage(
                        $this->app->Request->request->getInt('package_id'),
                        $this->app->Request->request->getInt('experiment_id'),
                    );
                    $this->app->Session->getFlashBag()->add('ok', sprintf('已关联实验，%d 份原件已校验并保存。', $Center->lastImportCount));
                } elseif ($action === 'create_agent') {
                    $created = $Center->createAgent($this->app->Request->request->getString('agent_name'),
                        $this->app->Request->request->getInt('recipient_id'));
                    $this->app->Session->getFlashBag()->add('ok', '已生成 Agent 接入令牌：' . $created['token'] . '（复制到仪器电脑的 agent 配置中）');
                }
            } catch (AppException $e) {
                $this->app->Session->getFlashBag()->add('ko', $e->getMessage());
            }
            return new RedirectResponse('/smartlab-data.php');
        }
        return parent::getResponse();
    }

    protected function getData(): array
    {
        $Center = new SmartlabDataCenter($this->app->Users);
        return array_merge(parent::getData(), array(
            'pendingPackages' => $Center->readPackages('pending'),
            'linkedPackages' => $Center->readPackages('linked', 10),
            'experimentOptions' => $Center->readExperimentOptions(),
            'agents' => $Center->readAgents(),
            'canManageAgents' => $Center->canManageAgents(),
            'agentRecipients' => $Center->canManageAgents() ? $this->app->Users->readAllActiveFromTeam() : array(),
        ));
    }
}
