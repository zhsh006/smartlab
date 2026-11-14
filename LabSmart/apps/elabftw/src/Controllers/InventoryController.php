<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2024 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Models\Config;
use Elabftw\Models\SmartlabInventory;
use Elabftw\Models\StorageUnits;
use Elabftw\Models\SmartlabWbConditions;
use Elabftw\Services\SmartlabAntibodyDatasheet;
use Elabftw\Elabftw\Db;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Override;

use function array_merge;

final class InventoryController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canAccessOperations($this->app->Users, $portalView)) {
            $this->app->Session->getFlashBag()->add('ko', '库存中心仅对助理端和导师端开放。');
            return new RedirectResponse('/dashboard.php');
        }

        if ($this->app->Request->isMethod('POST')
            && $this->app->Request->request->has('smartlab_action')) {
            if (!SmartlabPortalView::canManageInventory($this->app->Users, $portalView)) {
                $this->app->Session->getFlashBag()->add('ko', '库存登记与批次维护仅由助理端处理。');
                return new RedirectResponse('/inventory.php?smartlab_view=' . $portalView);
            }

            $SmartlabInventory = new SmartlabInventory($this->app->Users);
            $action = trim($this->app->Request->request->getString('smartlab_action'));

            if ($action === 'create_lot') {
                $itemId = $this->app->Request->request->getInt('item_id');
                $lotNo = trim($this->app->Request->request->getString('lot_no'));
                $expiresAt = trim($this->app->Request->request->getString('expires_at'));
                $quantity = $this->app->Request->request->get('quantity');
                $locationLabel = trim($this->app->Request->request->getString('location_label'));
                $note = trim($this->app->Request->request->getString('note'));
                $materialCode = trim($this->app->Request->request->getString('material_code'));
                $materialName = trim($this->app->Request->request->getString('material_name'));
                $materialCategory = trim($this->app->Request->request->getString('material_category'));
                $materialUnit = trim($this->app->Request->request->getString('material_unit'));
                $materialBrand = trim($this->app->Request->request->getString('material_brand'));
                $materialCatalogNo = trim($this->app->Request->request->getString('material_catalog_no'));
                $materialSpecification = trim($this->app->Request->request->getString('material_specification'));
                $materialPackageSize = trim($this->app->Request->request->getString('material_package_size'));

                if (($itemId > 0 || ($materialCode !== '' && $materialName !== '')) && $lotNo !== '') {
                    $wbProfile = $this->app->Request->request->all('wb_profile');
                    $wbSource = null;
                    $withWb = $materialCategory === 'antibody' && $this->app->Request->request->getBoolean('wb_profile_present');
                    if ($withWb) {
                        $file = $this->app->Request->files->get('wb_manual');
                        $text = $this->app->Request->request->getString('wb_text');
                        $wbSource = $file ? SmartlabAntibodyDatasheet::read($file) : ($text !== '' ? SmartlabAntibodyDatasheet::pasted($text) : null);
                    }
                    Db::getConnection()->transaction(function () use ($SmartlabInventory, $itemId, $lotNo, $expiresAt, $quantity, $locationLabel, $note, $materialCode, $materialName, $materialCategory, $materialUnit, $materialBrand, $materialCatalogNo, $materialSpecification, $materialPackageSize, $withWb, $wbProfile, $wbSource): void {
                    $materialId = $SmartlabInventory->createLot(
                        $itemId > 0 ? $itemId : null,
                        $lotNo,
                        $expiresAt !== '' ? $expiresAt : null,
                        $quantity !== null && $quantity !== '' ? (float) $quantity : null,
                        $locationLabel !== '' ? $locationLabel : null,
                        $note,
                        $materialCode !== '' ? $materialCode : null,
                        $materialName !== '' ? $materialName : null,
                        $materialCategory !== '' ? $materialCategory : null,
                        $materialUnit !== '' ? $materialUnit : null,
                        $materialBrand !== '' ? $materialBrand : null,
                        $materialCatalogNo !== '' ? $materialCatalogNo : null,
                        $materialSpecification !== '' ? $materialSpecification : null,
                        $materialPackageSize !== '' ? $materialPackageSize : null,
                    );
                    if ($withWb) { (new SmartlabWbConditions($this->app->Users))->saveProfile($materialId, $wbProfile, $wbSource); }
                    });
                    $this->app->Session->getFlashBag()->add('ok', '库存批次已创建。');
                }
            }

            if ($action === 'add_transaction') {
                $lotId = $this->app->Request->request->getInt('lot_id');
                $txnType = trim($this->app->Request->request->getString('txn_type'));
                $quantity = (float) $this->app->Request->request->get('quantity');
                $note = trim($this->app->Request->request->getString('note'));
                $experimentId = $this->app->Request->request->getInt('experiment_id');

                if ($lotId > 0 && $txnType !== '' && $quantity > 0) {
                    $SmartlabInventory->addTransaction(
                        $lotId,
                        $txnType,
                        $quantity,
                        $note,
                        $experimentId > 0 ? $experimentId : null,
                    );
                    $this->app->Session->getFlashBag()->add('ok', '出入库流水已登记。');
                }
            }

            return new RedirectResponse('/inventory.php?smartlab_view=' . $portalView);
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'inventory.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '库存';
    }

    #[Override]
    protected function getData(): array
    {
        $StorageUnits = new StorageUnits($this->app->Users, Config::getConfig()->configArr['inventory_require_edit_rights'] === '1');
        $containersArr = array();
        // only make a query if we do a search
        // set the 'limit' parameter for both readAll and readAllFromStorage. see #6116
        $this->app->Request->query->set('limit', 9999);
        if ($this->app->Request->query->has('q')) {
            $containersArr = $StorageUnits->readAll($StorageUnits->getQueryParams($this->app->Request->query));
        }
        if ($this->app->Request->query->has('storage_unit')) {
            $containersArr = $StorageUnits->readAllFromStorage($this->app->Request->query->getInt('storage_unit'));
        }
        $search = trim($this->app->Request->query->getString('q'));
        $SmartlabInventory = new SmartlabInventory($this->app->Users);
        return array_merge(
            parent::getData(),
            array(
                'smartlabInventorySummary' => $SmartlabInventory->readSummary(),
                'wbMethods' => (new SmartlabWbConditions($this->app->Users))->methods(),
                'smartlabLotSummary' => $SmartlabInventory->readLotSummary(),
                'smartlabInventoryArr' => $SmartlabInventory->readInventoryRows($search),
                'smartlabLotsArr' => $SmartlabInventory->readLots($search),
                'smartlabRecentTransactionsArr' => $SmartlabInventory->readRecentTransactions(),
                'smartlabAvailableItemsArr' => $SmartlabInventory->readAvailableItems(),
                'smartlabAvailableExperimentsArr' => $SmartlabInventory->readAvailableExperiments(),
                'containersArr' => $containersArr,
                'storageUnitsArr' => $StorageUnits->readAllRecursive(),
            ),
        );
    }
}
