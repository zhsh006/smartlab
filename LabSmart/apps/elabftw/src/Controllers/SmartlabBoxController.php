<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Services\SmartlabBoxService;
use Override;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SmartlabBoxController extends AbstractHtmlController
{
    private SmartlabBoxService $service;
    private string $message = '';
    private string $messageLevel = 'success';

    public function __construct(\Elabftw\Elabftw\App $app)
    {
        parent::__construct($app);
        $this->service = new SmartlabBoxService();
    }

    #[Override]
    public function getResponse(): Response
    {
        $this->assertCanManageBox();
        if ($this->app->Request->isMethod('POST')) {
            $this->handlePost();
        }
        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'smartlab-box.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return 'LabSmart Box 管理';
    }

    #[Override]
    protected function getData(): array
    {
        return array_merge(parent::getData(), array(
            'smartlabBoxConfig' => $this->service->readConfig(),
            'smartlabBoxStatus' => $this->service->readStatus(),
            'smartlabBoxMessage' => $this->message,
            'smartlabBoxMessageLevel' => $this->messageLevel,
        ));
    }

    private function handlePost(): void
    {
        $action = $this->app->Request->request->getString('box_action');
        try {
            if ($action === 'save_init') {
                $this->service->saveConfig($this->app->Request->request->all(), (int) $this->app->Users->team, (int) $this->app->Users->userData['userid']);
                $this->message = '初始化配置已保存。';
                return;
            }
            if ($action === 'run_backup') {
                $result = $this->service->createBackup((int) $this->app->Users->userData['userid']);
                $this->message = '备份已完成：' . basename($result['manifest']);
                return;
            }
            if ($action === 'upload_update') {
                $target = $this->service->storeUpdatePackage($this->app->Request->files->get('update_package'));
                $this->message = $target === null ? '未收到升级包。' : '升级包已保存，离线升级脚本入口已预留：' . basename($target);
                $this->messageLevel = $target === null ? 'warning' : 'success';
                return;
            }
            $this->message = '未知操作。';
            $this->messageLevel = 'warning';
        } catch (Throwable $e) {
            $this->message = $e->getMessage();
            $this->messageLevel = 'danger';
        }
    }

    private function assertCanManageBox(): void
    {
        $isSysadmin = (int) ($this->app->Users->userData['is_sysadmin'] ?? 0) === 1;
        if (!$this->app->Users->isAdmin && !$isSysadmin) {
            throw new ImproperActionException('LabSmart Box management is restricted to administrators.');
        }
    }
}
