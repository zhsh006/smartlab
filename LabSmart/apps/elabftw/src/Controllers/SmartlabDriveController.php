<?php

/**
 * "我的空间" personal drive controller (V3).
 *
 * @author SmartLab
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Exceptions\AppException;
use Elabftw\Models\SmartlabDataCenter;
use Elabftw\Models\SmartlabDrive;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class SmartlabDriveController extends AbstractHtmlController
{
    protected function getTemplate(): string
    {
        return 'smartlab-drive.html';
    }

    protected function getPageTitle(): string
    {
        return '我的空间';
    }

    #[Override]
    public function getResponse(): Response
    {
        if ($this->app->Request->isMethod('POST')) {
            $drive = new SmartlabDrive($this->app->Users);
            $action = $this->app->Request->request->getString('action');
            $path = SmartlabDrive::normalizePath($this->app->Request->request->getString('path'));
            try {
                if ($action === 'mkdir') {
                    $name = SmartlabDrive::normalizePath($this->app->Request->request->getString('name'));
                    $target = $name === '' ? $path : ($path === '' ? $name : $path . '/' . $name);
                    $drive->mkdir($target);
                    $this->app->Session->getFlashBag()->add('ok', '已创建文件夹。');
                } elseif ($action === 'delete') {
                    $n = $drive->deletePath($path);
                    $this->app->Session->getFlashBag()->add('ok', sprintf('已删除 %d 个文件。', $n));
                } elseif ($action === 'link') {
                    $n = $drive->linkToExperiment($path, $this->app->Request->request->getInt('experiment_id'));
                    $this->app->Session->getFlashBag()->add('ok', sprintf('已把 %d 个文件关联到实验（作为原始附件）。', $n));
                } elseif ($action === 'revoke_device') {
                    $drive->revokeDevice($this->app->Request->request->getInt('device_id'));
                    $this->app->Session->getFlashBag()->add('ok', '已断开该设备，需要重新登录。');
                } elseif ($action === 'upload') {
                    $this->handleBrowserUpload($drive, $path);
                }
            } catch (AppException $e) {
                $this->app->Session->getFlashBag()->add('ko', $e->getMessage());
            }
            $suffix = $path !== '' ? '?path=' . rawurlencode($path) : '';
            return new RedirectResponse('/smartlab-drive.php' . $suffix);
        }
        return parent::getResponse();
    }

    private function handleBrowserUpload(SmartlabDrive $drive, string $basePath): void
    {
        $files = $this->app->Request->files->get('files');
        if ($files === null) {
            throw new AppException('请选择要上传的文件。');
        }
        $count = 0;
        foreach (is_array($files) ? $files : array($files) as $file) {
            if ($file === null || !$file->isValid()) {
                continue;
            }
            $name = (string) $file->getClientOriginalName();
            $target = ($basePath === '' ? '' : $basePath . '/') . basename(str_replace('\\', '/', $name));
            $tmp = (string) $file->getRealPath();
            $drive->storeUploaded($target, $tmp, (string) hash_file('sha256', $tmp), (int) filesize($tmp), null, '网页上传', $name);
            ++$count;
        }
        if ($count === 0) {
            throw new AppException('没有可上传的有效文件。');
        }
        $this->app->Session->getFlashBag()->add('ok', sprintf('已上传 %d 个文件到我的空间。', $count));
    }

    protected function getData(): array
    {
        $drive = new SmartlabDrive($this->app->Users);
        $center = new SmartlabDataCenter($this->app->Users);
        return array_merge(parent::getData(), array(
            'space' => $drive->listSpace($this->app->Request->query->getString('path')),
            'experiments' => $center->readExperimentOptions(),
            'devices' => $drive->listDevices(),
        ));
    }
}
