<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Exceptions\ResourceNotFoundException;
use Elabftw\Models\SmartlabPrimerDesign;
use JsonException;
use Override;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use function array_merge;
use function json_decode;
use function trim;

use const JSON_THROW_ON_ERROR;

final class PrimerDesignController extends AbstractHtmlController
{
    #[Override]
    public function getResponse(): Response
    {
        $portalView = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canOperateExperiments($this->app->Users, $portalView)) {
            $this->app->Session->getFlashBag()->add('ko', '引物设计工具当前按学生端工作台组织。');
            return new RedirectResponse('/dashboard.php');
        }

        $Designer = new SmartlabPrimerDesign($this->app->Users);

        if ($this->app->Request->query->getString('action') === 'export') {
            $record = $Designer->readRecord($this->app->Request->query->getInt('record_id'));
            $content = $Designer->exportRecordAsExcelTsv($record);
            $response = new Response($content);
            $response->headers->set('Content-Type', 'application/vnd.ms-excel; charset=UTF-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="primer-design-' . $record['id'] . '.xls"');
            return $response;
        }

        if ($this->app->Request->isMethod('POST')
            && $this->app->Request->request->has('smartlab_action')) {
            $action = trim($this->app->Request->request->getString('smartlab_action'));

            if ($action === 'save_record') {
                try {
                    $recordId = $Designer->saveRecord(
                        trim($this->app->Request->request->getString('title')),
                        trim($this->app->Request->request->getString('sequence')),
                        json_decode($this->app->Request->request->getString('parameters_json'), true, 512, JSON_THROW_ON_ERROR),
                        json_decode($this->app->Request->request->getString('results_json'), true, 512, JSON_THROW_ON_ERROR),
                        $this->app->Request->request->getInt('experiment_id') ?: null,
                    );
                    $this->app->Session->getFlashBag()->add('ok', '引物设计记录已保存。');
                    return new RedirectResponse('/primer-design.php?record_id=' . $recordId);
                } catch (JsonException | RuntimeException $e) {
                    $this->app->Session->getFlashBag()->add('ko', $e->getMessage());
                    return new RedirectResponse('/primer-design.php');
                }
            }
        }

        return parent::getResponse();
    }

    #[Override]
    protected function getTemplate(): string
    {
        return 'primer-design.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return '引物设计';
    }

    #[Override]
    protected function getData(): array
    {
        $Designer = new SmartlabPrimerDesign($this->app->Users);
        $designResult = null;
        $activeRecord = null;
        $formInput = array(
            'title' => '',
            'sequence' => '',
            'experiment_type' => 'qpcr',
            'product_size_range' => '80-200',
            'primer_size_min' => 18,
            'primer_size_opt' => 20,
            'primer_size_max' => 24,
            'tm_min' => 57,
            'tm_opt' => 60,
            'tm_max' => 63,
            'gc_min' => 40,
            'gc_max' => 60,
            'num_return' => 5,
            'experiment_id' => 0,
        );

        if ($this->app->Request->isMethod('POST')
            && $this->app->Request->request->getString('smartlab_action') === 'design_primers') {
            $formInput = array_merge($formInput, $this->app->Request->request->all());
            try {
                $designResult = $Designer->design($formInput);
                if ($designResult['pairs'] === array()) {
                    $this->app->Session->getFlashBag()->add('ko', 'Primer3 未返回候选引物，请放宽长度、Tm 或 GC 条件后重试。');
                }
            } catch (RuntimeException $e) {
                $this->app->Session->getFlashBag()->add('ko', $e->getMessage());
            }
        }

        $recordId = $this->app->Request->query->getInt('record_id');
        if ($recordId > 0) {
            try {
                $activeRecord = $Designer->readRecord($recordId);
            } catch (JsonException | ResourceNotFoundException | RuntimeException) {
                $this->app->Session->getFlashBag()->add('ko', '无法读取该引物设计记录。');
            }
        }

        return array_merge(parent::getData(), array(
            'primerDesignInput' => $formInput,
            'primerDesignResult' => $designResult,
            'primerDesignRecordsArr' => $Designer->readRecords(),
            'primerDesignActiveRecord' => $activeRecord,
            'primerDesignQpcrExperimentsArr' => $Designer->readQpcrExperiments(),
        ));
    }
}
