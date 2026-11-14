<?php
declare(strict_types=1);
// Synthetic data for the isolated preview. Never seed these as laboratory defaults.
if (getenv('SITE_URL') !== 'https://localhost:3160') { throw new RuntimeException('Isolated preview required.'); }
chdir('/elabftw'); require 'vendor/autoload.php';
use Elabftw\Elabftw\Db;
use Elabftw\Models\Users\Users;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\SmartlabWbConditions;
use Elabftw\Services\SmartlabAntibodyDatasheet;
use Symfony\Component\HttpFoundation\File\UploadedFile;
$db=Db::getConnection(); $admin=new Users(1,1); $student=new Users(2,1);
$catalog=new SmartlabWbConditions($admin); $assay=new SmartlabExperiments($student);
// Existing legacy models perform DDL in their constructors; initialize before transaction.
new \Elabftw\Models\SmartlabBenchRecords($student);
new \Elabftw\Models\SmartlabReviews($student);
$pdf='/elabftw/cache/WB-SYNTHETIC-manual.pdf';
$source=SmartlabAntibodyDatasheet::read(new UploadedFile($pdf,'WB-SYNTHETIC-manual.pdf',test:true));
if (($source['fields']['host_species']??'')!=='Rabbit' || ($source['fields']['reactivity']??'')!=='Human, Mouse' || ($source['fields']['wb_dilution']??'')!=='1:1000') { throw new RuntimeException('PDF pipeline field extraction failed.'); }
if ($source['hash']!==hash_file('sha256',$pdf)) { throw new RuntimeException('PDF bytes changed.'); }
$id=$db->q('SELECT id FROM experiments WHERE title="WB 使用流程演示（合成数据）" AND userid=2 LIMIT 1')->fetchColumn();
if (!$id) {
    $db->transaction(function() use($db,$catalog,$source,$assay,$student,&$id):void {
        $db->q('INSERT INTO smartlab_materials (team_id,code,name,category,unit,brand,catalog_no,created_by) VALUES (1,"PREVIEW_SYNTHETIC_A","演示抗体 A（合成资料）","antibody","uL","合成演示","DEMO-A",1)');
        $material=$db->lastInsertId();
        $catalog->saveMethod(array('code'=>'PREVIEW_SYNTHETIC_WB','name'=>'演示方案（非实验室 SOP）','expected_revision'=>0,
            'quantification'=>'合成演示：BCA','loading'=>'20 μg / lane（合成演示）','gel'=>'10%（合成演示）',
            'electrophoresis'=>'合成演示；未使用实验室 SOP','transfer'=>'合成演示；未使用实验室 SOP',
            'membrane'=>'PVDF（合成演示）','applicability'=>'仅演示软件流程，不可作为真实实验条件','is_default'=>'0'));
        $catalog->saveProfile($material,array_merge($source['fields'],array('expected_revision'=>0,'reviewed'=>'1',
            'role'=>'primary','method_code'=>'PREVIEW_SYNTHETIC_WB','source_note'=>'合成说明书，仅用于预览软件流程。')),$source);
        $id=(new Experiments($student))->create(title:'WB 使用流程演示（合成数据）');
        $assay->initializeAssayPack($id,'western_blot'); $assay->linkProject($id,1);
    });
}
echo json_encode(array('pdf_extraction'=>'passed','pdf_sha256'=>$source['hash'],'experiment_id'=>(int)$id),JSON_THROW_ON_ERROR)."\n";
