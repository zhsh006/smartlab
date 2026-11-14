<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2026 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Models\SmartlabAssayCatalog;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Override;

use function array_merge;

final class AssayCatalogController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canAccessOperations($this->app->Users, $portalView)) {
            $this->app->Session->getFlashBag()->add('ko', '对象字典仅对助理端和导师端开放。');
            return new RedirectResponse('/dashboard.php');
        }

        if ($this->app->Request->isMethod('POST')
            && $this->app->Request->request->has('smartlab_action')) {
            if (!SmartlabPortalView::canManageAssayCatalog($this->app->Users, $portalView)) {
                $this->app->Session->getFlashBag()->add('ko', '对象字典维护仅由助理端处理。');
                return new RedirectResponse('/assay-catalog.php?smartlab_view=' . $portalView);
            }

            $Catalog = new SmartlabAssayCatalog($this->app->Users);
            $action = trim($this->app->Request->request->getString('smartlab_action'));

            if ($action === 'save_primer') {
                $Catalog->savePrimer(
                    $this->app->Request->request->getInt('id'),
                    trim($this->app->Request->request->getString('code')),
                    trim($this->app->Request->request->getString('target_gene')),
                    trim($this->app->Request->request->getString('reference_gene')) ?: null,
                    trim($this->app->Request->request->getString('amplicon_bp')) ?: null,
                    trim($this->app->Request->request->getString('annealing_temperature')) ?: null,
                    trim($this->app->Request->request->getString('note')),
                );
                $this->app->Session->getFlashBag()->add('ok', '引物对象已保存。');
            }

            if ($action === 'save_antibody') {
                $Catalog->saveAntibody(
                    $this->app->Request->request->getInt('id'),
                    trim($this->app->Request->request->getString('code')),
                    trim($this->app->Request->request->getString('name')),
                    trim($this->app->Request->request->getString('application_scope')),
                    trim($this->app->Request->request->getString('host_species')) ?: null,
                    trim($this->app->Request->request->getString('dilution_hint')) ?: null,
                    trim($this->app->Request->request->getString('target_name')) ?: null,
                    trim($this->app->Request->request->getString('note')),
                );
                $this->app->Session->getFlashBag()->add('ok', '抗体对象已保存。');
            }

            if ($action === 'save_reagent') {
                $Catalog->saveReagent(
                    $this->app->Request->request->getInt('id'),
                    trim($this->app->Request->request->getString('code')),
                    trim($this->app->Request->request->getString('name')),
                    trim($this->app->Request->request->getString('reagent_scope')),
                    trim($this->app->Request->request->getString('vendor')) ?: null,
                    trim($this->app->Request->request->getString('model_hint')) ?: null,
                    trim($this->app->Request->request->getString('note')),
                );
                $this->app->Session->getFlashBag()->add('ok', '试剂对象已保存。');
            }

            if ($action === 'archive_primer') {
                $Catalog->setPrimerActive($this->app->Request->request->getInt('id'), false);
                $this->app->Session->getFlashBag()->add('ok', '引物对象已归档。');
            }

            if ($action === 'restore_primer') {
                $Catalog->setPrimerActive($this->app->Request->request->getInt('id'), true);
                $this->app->Session->getFlashBag()->add('ok', '引物对象已恢复。');
            }

            if ($action === 'archive_antibody') {
                $Catalog->setAntibodyActive($this->app->Request->request->getInt('id'), false);
                $this->app->Session->getFlashBag()->add('ok', '抗体对象已归档。');
            }

            if ($action === 'restore_antibody') {
                $Catalog->setAntibodyActive($this->app->Request->request->getInt('id'), true);
                $this->app->Session->getFlashBag()->add('ok', '抗体对象已恢复。');
            }

            if ($action === 'archive_reagent') {
                $Catalog->setReagentActive($this->app->Request->request->getInt('id'), false);
                $this->app->Session->getFlashBag()->add('ok', '试剂对象已归档。');
            }

            if ($action === 'restore_reagent') {
                $Catalog->setReagentActive($this->app->Request->request->getInt('id'), true);
                $this->app->Session->getFlashBag()->add('ok', '试剂对象已恢复。');
            }

            return new RedirectResponse('/assay-catalog.php?smartlab_view=' . $portalView);
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'assay-catalog.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return 'Assay Catalog';
    }

    #[Override]
    protected function getData(): array
    {
        $search = trim($this->app->Request->query->getString('q'));
        $editType = trim($this->app->Request->query->getString('edit_type'));
        $editId = $this->app->Request->query->getInt('edit_id');
        $Catalog = new SmartlabAssayCatalog($this->app->Users);

        $primerEditor = array(
            'id' => 0,
            'code' => '',
            'target_gene' => '',
            'reference_gene' => '',
            'amplicon_bp' => '',
            'annealing_temperature' => '',
            'note' => '',
        );
        $antibodyEditor = array(
            'id' => 0,
            'code' => '',
            'name' => '',
            'application_scope' => 'western_blot',
            'host_species' => '',
            'dilution_hint' => '',
            'target_name' => '',
            'note' => '',
        );
        $reagentEditor = array(
            'id' => 0,
            'code' => '',
            'name' => '',
            'reagent_scope' => 'qpcr_kit',
            'vendor' => '',
            'model_hint' => '',
            'note' => '',
        );
        $editorUsageArr = array();

        if ($editType === 'primer' && $editId > 0) {
            $primerEditor = array_replace($primerEditor, $Catalog->readPrimerById($editId) ?? array());
            $editorUsageArr = $Catalog->readPrimerUsage($editId);
        }
        if ($editType === 'antibody' && $editId > 0) {
            $antibodyEditor = array_replace($antibodyEditor, $Catalog->readAntibodyById($editId) ?? array());
            $editorUsageArr = $Catalog->readAntibodyUsage($editId);
        }
        if ($editType === 'reagent' && $editId > 0) {
            $reagentEditor = array_replace($reagentEditor, $Catalog->readReagentById($editId) ?? array());
            $editorUsageArr = $Catalog->readReagentUsage($editId);
        }

        return array_merge(
            parent::getData(),
            array(
                'smartlabAssayCatalogSummary' => $Catalog->readSummary(),
                'smartlabAssayCatalogSearch' => $search,
                'smartlabAssayCatalogEditType' => $editType,
                'smartlabPrimerEditor' => $primerEditor,
                'smartlabAntibodyEditor' => $antibodyEditor,
                'smartlabReagentEditor' => $reagentEditor,
                'smartlabAssayCatalogEditorUsageArr' => $editorUsageArr,
                'smartlabPrimerCatalogArr' => $Catalog->readPrimers($search),
                'smartlabAntibodyCatalogArr' => $Catalog->readAntibodies($search),
                'smartlabReagentCatalogArr' => $Catalog->readReagents($search),
            ),
        );
    }
}
