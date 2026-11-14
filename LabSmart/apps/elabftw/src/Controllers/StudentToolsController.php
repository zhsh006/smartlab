<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\SmartlabPortalView;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class StudentToolsController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canOperateExperiments($this->app->Users, $portalView)) {
            $this->app->Session->getFlashBag()->add('ko', '科研工具入口当前按学生端工作台组织。');
            return new RedirectResponse('/dashboard.php');
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'student-tools.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '科研工具';
    }
}
