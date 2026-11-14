<?php
declare(strict_types=1);
namespace Elabftw\Controllers;

use Elabftw\Elabftw\SmartlabPortalView;
use Elabftw\Exceptions\AppException;
use Elabftw\Models\SmartlabWbConditions;
use Elabftw\Services\SmartlabAntibodyDatasheet;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class WbConditionsController extends AbstractHtmlController
{
    public function getResponse(): Response
    {
        $catalog = new SmartlabWbConditions($this->app->Users);
        if ($this->app->Request->query->getInt('source_id') > 0) {
            $source = $catalog->source($this->app->Request->query->getInt('source_id'));
            return new Response($source['source_bytes'], 200, array('Content-Type' => $source['source_mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $source['source_name'], 'antibody-manual.' . ($source['source_mime'] === 'application/pdf' ? 'pdf' : 'txt'))));
        }
        $view = SmartlabPortalView::resolve($this->app->Request, $this->app->Session, $this->app->Users);
        if (!SmartlabPortalView::canAccessOperations($this->app->Users, $view)) { return new RedirectResponse('/dashboard.php'); }
        if ($this->app->Request->isMethod('POST')) {
            if (!SmartlabPortalView::canManageAssayCatalog($this->app->Users, $view)) { throw new AppException('请由入库人员维护说明书和常用方案。'); }
            $action = $this->app->Request->request->getString('smartlab_action');
            if ($action === 'extract_wb_sheet') {
                try {
                    $file = $this->app->Request->files->get('wb_manual');
                    $text = $this->app->Request->request->getString('wb_text');
                    $source = $file ? SmartlabAntibodyDatasheet::read($file) : null;
                    if ($text === '' && !$source) { throw new AppException('请选择说明书，或粘贴说明书文字。'); }
                    $fields = $text !== '' ? SmartlabAntibodyDatasheet::extract($text) : $source['fields'];
                    return new JsonResponse(array('ok' => true, 'fields' => $fields, 'message' => $fields ? '已提取明确写出的内容，请核对后保存。' : '没有识别到明确字段；扫描件可粘贴文字，或直接填写已核实的条件。'));
                } catch (AppException $error) { return new JsonResponse(array('ok' => false, 'message' => $error->getMessage()), 422); }
            }
            if ($action === 'save_wb_profile') {
                $file = $this->app->Request->files->get('wb_manual');
                $text = $this->app->Request->request->getString('wb_text');
                $source = $file ? SmartlabAntibodyDatasheet::read($file) : ($text !== '' ? SmartlabAntibodyDatasheet::pasted($text) : null);
                $fields = $this->app->Request->request->all('wb_profile');
                $catalog->saveProfile($this->app->Request->request->getInt('material_id'), $fields, $source);
                $this->app->Session->getFlashBag()->add('ok', '抗体说明书与 WB 条件已保存，新实验可以选择带入。');
                return new RedirectResponse('/wb-conditions.php?smartlab_view=assistant&material_id=' . $this->app->Request->request->getInt('material_id'));
            }
            if ($action === 'save_wb_method') {
                $catalog->saveMethod($this->app->Request->request->all());
                $this->app->Session->getFlashBag()->add('ok', '常用 WB 方案已保存；旧实验继续保留原来的方案版本。');
                return new RedirectResponse('/wb-conditions.php?smartlab_view=assistant&method_code=' . rawurlencode($this->app->Request->request->getString('code')) . '#wb-methods');
            }
            throw new AppException('不支持的 WB 设置操作。');
        }
        return parent::getResponse();
    }

    protected function getTemplate(): string { return 'wb-conditions.html'; }
    protected function getPageTitle(): string { return '抗体说明书与 WB 常用方案'; }
    protected function getData(): array
    {
        $catalog = new SmartlabWbConditions($this->app->Users);
        $materialId = $this->app->Request->query->getInt('material_id');
        $material = $materialId > 0 ? $catalog->material($materialId) : null;
        $profile = $material ? $catalog->latestProfile($materialId) : null;
        $methods = $catalog->methods(); $method = null;
        foreach ($methods as $row) { if ($row['code'] === $this->app->Request->query->getString('method_code')) { $method = $row; break; } }
        return array_merge(parent::getData(), array('wbProfiles' => $catalog->profiles(), 'wbMaterial' => $material, 'wbProfile' => $profile, 'wbMethods' => $methods,
            'wbMethod' => $method, 'wbMethodFields' => SmartlabWbConditions::METHOD_FIELDS));
    }
}
