<?php
declare(strict_types=1);
// Pure parsing, projection and real Twig rendering. No database or login is simulated.
$root = dirname(__DIR__);
$runtime = $root . '/.local/wb-check-runtime';
require $runtime . '/symfony-deprecation-contracts/function.php';
spl_autoload_register(static function (string $class) use ($root, $runtime): void {
    foreach (array('Twig\\' => $runtime . '/twig-twig/src/', 'Elabftw\\' => $root . '/apps/elabftw/src/') as $prefix => $path) {
        if (str_starts_with($class, $prefix)) { $file = $path . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; if (is_file($file)) { require $file; } }
    }
});
function verify(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
use Elabftw\Services\SmartlabAntibodyDatasheet;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabWbConditions;
$text = "Host Species: Rabbit\nSpecies Reactivity: Human, Mouse, Rat\nTarget: GAPDH\nMW (kDa): 37\nWestern Blotting  1:1000\nImmunofluorescence  1:100–1:400\nWB Diluent: 5% milk in TBST\nWB Incubation: 4°C overnight";
$parsed = SmartlabAntibodyDatasheet::extract($text);
verify($parsed['host_species'] === 'Rabbit' && $parsed['reactivity'] === 'Human, Mouse, Rat', 'Host and reactivity were mixed.');
verify($parsed['wb_dilution'] === '1:1000' && $parsed['molecular_weight'] === '37 kDa', 'WB application/weight extraction failed.');
verify(!isset(SmartlabAntibodyDatasheet::extract("IF  1:200\nIHC  1:400")['wb_dilution']), 'IF/IHC recommendation leaked into WB.');
verify(SmartlabAntibodyDatasheet::extract("WB: 1:1000–1:4000")['wb_dilution'] === '1:1000–1:4000', 'Recommendation range was invented as an exact value.');
verify(SmartlabAntibodyDatasheet::extract('Scan without readable fields') === array(), 'Unknown values were guessed.');
$volumes = \Elabftw\Services\SmartlabWbLoadingCalculator::calculate('20 μg / lane',"S1,2,mg/mL\nS2,2000,μg/mL\nS3,0,mg/mL\nS4,2,unknown");
verify($volumes[0]['volume_ul']===10.0 && $volumes[1]['volume_ul']===10.0 && $volumes[2]['volume_ul']===null && $volumes[3]['volume_ul']===null,'Concentration unit conversion/unknown handling failed.');
verify(\Elabftw\Services\SmartlabWbLoadingCalculator::calculate('20–30 μg', 'S1,2,mg/mL')[0]['volume_ul']===null,'A loading range became an exact mass.');
$benchModel = (new ReflectionClass(SmartlabBenchRecords::class))->newInstanceWithoutConstructor();
$method = array('id' => 1, 'code' => 'DEMO', 'name' => '组件测试方案（合成数据）', 'revision' => 1, 'fields' => array('quantification' => 'BCA（演示）', 'loading' => '20 μg / lane（演示）', 'gel' => '10%（演示）', 'electrophoresis' => '设备 SOP（演示）', 'transfer' => '湿转 SOP（演示）', 'membrane' => 'PVDF（演示）', 'applicability' => '仅用于组件测试'));
$profile = array_replace(array_fill_keys(SmartlabWbConditions::PROFILE_FIELDS, ''), $parsed, array('role' => 'primary'));
$antibody = array('material_id' => 1, 'profile_id' => 1, 'revision' => 1, 'name' => '演示抗体 A', 'brand' => 'DEMO', 'catalog_no' => 'DEMO-A', 'source_name' => '', 'source_hash' => '', 'fields' => $profile);
$rows = array(); foreach (SmartlabWbConditions::METHOD_FIELDS as $key => $label) { $rows[$key] = array('label' => $label, 'value' => $method['fields'][$key], 'source' => $method['name'] . ' · V1'); }
foreach (array('wb_dilution' => '稀释比例', 'wb_incubation' => '孵育条件', 'wb_diluent' => '稀释液') as $key => $label) { $rows['antibody.1.' . $key] = array('label' => '演示抗体 A · ' . $label, 'value' => $profile[$key], 'source' => '说明书推荐 · V1'); }
$setup = array('id' => 1, 'revision' => 1, 'data' => array('snapshot' => array('antibodies' => array($antibody), 'secondary' => null, 'method' => $method, 'rows' => $rows)));
$template = SmartlabBenchRecords::templates()['western_blot'];
$steps = array(); foreach ($template['steps'] as $definition) { foreach ($definition['fields'] as &$field) { $field['planned']=''; $field['candidate']=''; $field['candidate_source']='unknown'; } unset($field); $steps[] = array('definition'=>$definition,'record'=>null); }
$context = array('experiment_id'=>999,'template_key'=>'western_blot','template'=>$template,'version'=>'bench-v1','records'=>array('wb.setup'=>$setup), 'steps'=>$steps, 'plan'=>null, 'uploads'=>array(), 'snapshot'=>false, 'legacy_objective'=>'', 'operator'=>'演示学生');
$view = $benchModel->wbView($context);
verify($view['rows']['antibody.1.wb_dilution']['state'] === 'default_unconfirmed' && !$view['actual'], 'Selection was shown as actual execution.');
verify($view['rows']['exposure']['state'] === 'unknown' && $view['rows']['exposure']['candidate'] === '', 'Exposure was guessed.');
$context['records']['wb.progress'] = array('id'=>2,'revision'=>1,'data'=>array('fields'=>array('stage'=>'transfer')));
verify(SmartlabBenchRecords::progress($template,$context['records'])['resume_key'] === 'transfer', 'Cross-day progress was lost.');
$context['records']['wb.actual'] = array('id'=>3,'revision'=>1,'data'=>array('state'=>'confirmed','setup_revision'=>1,'fields'=>array('loading'=>'30 μg / lane','antibody.1.wb_dilution'=>'1:2000','note'=>''),'parameter_states'=>array('loading'=>'overridden','antibody.1.wb_dilution'=>'overridden')));
$view2 = $benchModel->wbView($context);
verify($view2['rows']['loading']['candidate'] === '30 μg / lane' && $view2['rows']['loading']['value'] === $method['fields']['loading'], 'Actual/reference separation was lost.');
$context['records']['wb.setup']['revision'] = 2;
verify(!$benchModel->wbView($context)['current_actual'], 'A new selection reused old confirmation.');
$context['records'] = array('wb.setup'=>$setup,'wb.progress'=>$context['records']['wb.progress']);

$twig = new Twig\Environment(new Twig\Loader\ChainLoader(array(new Twig\Loader\ArrayLoader(array('base.html'=>'{% block body %}{% endblock %}')), new Twig\Loader\FilesystemLoader($root . '/apps/elabftw/src/templates'))), array('strict_variables'=>true));
$twig->addFilter(new Twig\TwigFilter('csrf',static fn($token)=>'<input type="hidden" name="csrf" value="COMPONENT_PREVIEW">',array('is_safe'=>array('html'))));
$twig->addFilter(new Twig\TwigFilter('trans',static fn($text)=>$text));
$twig->addFilter(new Twig\TwigFilter('msg',static fn($text)=>$text));
foreach (array('smartlab-record.html','smartlab-wb-quick.html','smartlab-wb-actual-table.html','wb-conditions.html','wb-manual-fields.html','inventory.html','bench-pdf.html') as $file) { $twig->parse($twig->tokenize(new Twig\Source(file_get_contents($root . '/apps/elabftw/src/templates/' . $file),$file))); }
$session = new class { public function get(string $key): string { return 'COMPONENT_PREVIEW'; } };
$data = array('Entity'=>(object) array('id'=>999,'isReadOnly'=>false,'entityData'=>array('title'=>'WB 工作区 · 组件测试')), 'editable'=>true, 'App'=>array('Session'=>$session), 'bench'=>$context,
    'wbView'=>$benchModel->wbView($context), 'wbProfiles'=>array($antibody),'wbMethods'=>array($method),'wbCatalogToken'=>'COMPONENT_PREVIEW', 'experimentProgress'=>SmartlabBenchRecords::progress($template,$context['records']));
$fullData = array_merge($data,array('smartlabCanManageExperiments'=>true,'pageTitle'=>'组件测试','experimentCode'=>'DEMO-999','projectLabel'=>'组件测试','currentStepIndex'=>0,'historyFullView'=>array(),'historyView'=>array(),'smartlabAvailableSamplesArr'=>array(),'smartlabAvailableLotsArr'=>array(),'smartlabAssayPresetCatalogArr'=>array()));
$fullHtml = $twig->render('smartlab-record.html',$fullData);
verify(str_contains($fullHtml,'data-wb-quick') && !str_contains($fullHtml,'data-wb-light'), 'WB start still exposed the old step forms.');
$twig->render('wb-manual-fields.html',array('profilePrefix'=>'test','wbProfile'=>null,'wbMethods'=>array($method)));
$out = $root . '/docs/v3-wb-conditions-20261014/preview'; if (!is_dir($out)) { mkdir($out, 0777, true); }
$css = file_get_contents($root . '/apps/elabftw/web/assets/labsmart-record.css');
$baseCss = 'body{font-family:system-ui,Arial,sans-serif;margin:0;background:#f4f6f9;color:#243447}.preview-wrap{max-width:1060px;margin:20px auto;padding:0 16px}.preview-label{background:#fff0d3;padding:12px;font-size:14px;margin-bottom:16px}.form-control{box-sizing:border-box;width:100%;padding:9px 12px;border:1px solid #cad3de;border-radius:5px;background:#fff;color:#243447}.btn{border:1px solid #b9c7d5;border-radius:5px;padding:9px 12px;background:#fff;color:#31475e;cursor:pointer}.btn-primary{background:#1c68a5;color:#fff;border-color:#1c68a5}.smallgray{color:#65778a;font-size:14px}.bench-card{padding:22px;background:white;border:1px solid #e1e7ef;border-radius:10px}.bench-card-heading{display:flex;justify-content:space-between;align-items:center;gap:12px}.bench-card-heading h3{margin:0 0 16px}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}[hidden]{display:none!important}a{color:#1c68a5}label{display:block;margin-bottom:6px}.mb-0{margin-bottom:0}.mt-2{margin-top:8px}';
$html = '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WB 组件测试</title><style>' . $baseCss . $css . '</style><div class="preview-wrap"><div class="preview-label">组件预览 · 合成数据 · 未连接数据库，保存操作不可用</div>' . $twig->render('smartlab-wb-quick.html',$data) . '</div><script src="smartlab-wb-quick.js"></script></html>';
file_put_contents($out . '/wb-component.html',$html); copy($root . '/apps/elabftw/web/assets/smartlab-wb-quick.js',$out . '/smartlab-wb-quick.js');
echo "Extraction: WB/IF separation, host/reactivity separation, ranges, missing values passed.\nProjection: reference/actual separation, progress, selection changes passed.\nTwig syntax and component render passed.\n";
