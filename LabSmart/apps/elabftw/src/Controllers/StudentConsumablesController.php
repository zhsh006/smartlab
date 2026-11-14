<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Models\SmartlabInventory;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use function array_merge;

final class StudentConsumablesController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canOperateExperiments($this->app->Users, $portalView)) {
            $this->app->Session->getFlashBag()->add('ko', '我的耗材入口仅用于学生端。');
            return new RedirectResponse('/dashboard.php');
        }

        if ($this->app->Request->isMethod('POST')
            && $this->app->Request->request->has('smartlab_action')) {
            $action = trim($this->app->Request->request->getString('smartlab_action'));
            if ($action === 'hide_my_consumable') {
                $transactionId = $this->app->Request->request->getInt('transaction_id');
                if ($transactionId > 0) {
                    (new SmartlabInventory($this->app->Users))->hideMyConsumable($transactionId);
                    $this->app->Session->getFlashBag()->add('ok', '已从我的耗材中移除。');
                }
            }

            return new RedirectResponse('/student-consumables.php');
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'student-consumables.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '我的耗材';
    }

    #[Override]
    protected function getData(): array
    {
        $search = trim($this->app->Request->query->getString('q'));
        $SmartlabInventory = new SmartlabInventory($this->app->Users);

        return array_merge(parent::getData(), array(
            'smartlabMyConsumablesArr' => $SmartlabInventory->readMyConsumables(100, $search),
            'smartlabMyConsumablesSearch' => $search,
        ));
    }
}
