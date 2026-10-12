<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Models\SmartlabInventory;
use Override;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use function array_merge;
use function ceil;
use function max;
use function min;

final class StudentInventoryController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canOperateExperiments($this->app->Users, $portalView)) {
            $this->app->Session->getFlashBag()->add('ko', '课题组库存查询入口仅用于学生端。');
            return new RedirectResponse('/dashboard.php');
        }

        if ($this->app->Request->isMethod('POST')
            && $this->app->Request->request->has('smartlab_action')) {
            $action = trim($this->app->Request->request->getString('smartlab_action'));
            if ($action === 'request_use') {
                $lotId = $this->app->Request->request->getInt('lot_id');
                $quantity = (float) $this->app->Request->request->get('quantity');
                $experimentId = $this->app->Request->request->getInt('experiment_id') ?: null;
                $note = trim($this->app->Request->request->getString('note'));

                if ($lotId > 0 && $quantity > 0) {
                    (new SmartlabInventory($this->app->Users))->requestUse($lotId, $quantity, $note, $experimentId);
                    $this->app->Session->getFlashBag()->add('ok', '领用申请已提交，等待库存负责人处理。');
                } else {
                    $this->app->Session->getFlashBag()->add('ko', '请选择库存物料并填写大于 0 的申请数量。');
                }
            }

            if ($action === 'hide_my_consumable') {
                $transactionId = $this->app->Request->request->getInt('transaction_id');
                if ($transactionId > 0) {
                    (new SmartlabInventory($this->app->Users))->hideMyConsumable($transactionId);
                    $this->app->Session->getFlashBag()->add('ok', '已从我的耗材中移除。');
                }
                return new RedirectResponse('/student-consumables.php');
            }

            return new RedirectResponse('/student-inventory.php');
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'student-inventory.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '课题组库存';
    }

    #[Override]
    protected function getData(): array
    {
        $search = trim($this->app->Request->query->getString('q'));
        $pageSize = 15;
        $page = max(1, $this->app->Request->query->getInt('page'));
        $SmartlabInventory = new SmartlabInventory($this->app->Users);
        $lotCount = $SmartlabInventory->countLots($search);
        $pageCount = max(1, (int) ceil($lotCount / $pageSize));
        $page = min($page, $pageCount);
        $offset = ($page - 1) * $pageSize;

        return array_merge(parent::getData(), array(
            'smartlabInventorySummary' => $SmartlabInventory->readSummary(),
            'smartlabLotSummary' => $SmartlabInventory->readLotSummary(),
            'smartlabInventoryArr' => $SmartlabInventory->readInventoryRows($search),
            'smartlabLotsArr' => $SmartlabInventory->readLots($search, $pageSize, $offset),
            'smartlabInventoryLotCount' => $lotCount,
            'smartlabInventoryPage' => $page,
            'smartlabInventoryPageCount' => $pageCount,
            'smartlabInventoryPageSize' => $pageSize,
            'smartlabRecentTransactionsArr' => $SmartlabInventory->readRecentTransactions(),
            'smartlabMyConsumablesArr' => $SmartlabInventory->readMyConsumables(),
            'smartlabInventorySearch' => $search,
            'smartlabAvailableExperimentsArr' => $SmartlabInventory->readAvailableExperiments(),
        ));
    }
}
