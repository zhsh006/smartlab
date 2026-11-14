import fs from 'node:fs/promises';
import path from 'node:path';
import {Presentation,PresentationFile} from '@oai/artifact-tool';
const root=path.resolve('..');
const assets=path.join(root,'assets');
await fs.mkdir(assets,{recursive:true});
const gen='C:/Users/zhsh0/.codex/generated_images/01a12e2d-d294-7a22-a30a-c3cf6c4e30d1';
for(const [src,name] of [
 ['exec-b8b4ad0a-eabe-4505-bfa8-cedab81faab0.png','01-experiment-story.png'],
 ['exec-197e6a9f-8899-4324-b4a8-7640103d41bb.png','02-conditions-story.png'],
 ['exec-4fbb3002-bdda-40a3-ad56-7e5da34f1248.png','03-demo-workbench.png']
]) await fs.copyFile(path.join(gen,src),path.join(assets,name));
await fs.copyFile('C:/项目汇总/AGI项目/smartlab/LabSmart/docs/ux-review-20261015/01-record-overview.png',path.join(assets,'WB-current-preview.png'));
const p=Presentation.create({slideSize:{width:1280,height:720}});
const color={ink:'#153F3C',muted:'#586B62',accent:'#BE543B',paper:'#F4F0E6'};
const font='Microsoft YaHei';
function text(s,name,copy,x,y,w,h,size=24,opts={}) {
 const t=s.shapes.add({name,geometry:'textbox',position:{left:x,top:y,width:w,height:h},fill:'none',line:{fill:'none',width:0,style:'solid'}});
 t.text=copy;
 t.text.style={fontSize:size,typeface:opts.serif?'SimSun':font,color:opts.color??color.ink,bold:opts.bold??false,wrap:'none',autoFit:'none',insets:0,verticalAlignment:'middle',alignment:opts.align??'left',...opts};
 return t;
}
async function img(s,file,pos,alt,extra={}){
 const b=await fs.readFile(path.join(assets,file));
 return s.images.add({blob:b.buffer.slice(b.byteOffset,b.byteOffset+b.byteLength),contentType:'image/png',fit:'cover',position:pos,alt,...extra});
}
async function page(asset,n,section){
 const s=p.slides.add();s.background.fill=color.paper;
 await img(s,asset,{left:0,top:0,width:1280,height:720},'SmartLab 科研场景原创插画');
 text(s,'section-eyebrow','SMARTLAB  /  '+section,56,33,1000,25,16,{bold:true});
 text(s,'footer-brand','SmartLab · 科研实验工作台',56,675,700,20,13,{color:color.muted});
 text(s,'footer-page',String(n).padStart(2,'0'),1184,675,40,20,14,{color:color.muted,align:'right'});
 return s;
}
const source='https://blog.google/innovation-and-ai/models-and-research/google-labs/8-ways-to-make-the-most-out-of-slide-decks-in-notebooklm/';
const inspiration='https://www.knowwhowearsthegenesinyourfamily.com/blog/combining-google-notebooklms-slide-deck-with-google-slides-and-google-vids-a-fast-powerful-new-workflow-for-genealogists';
const s1=await page('01-experiment-story.png',1,'认识 SmartLab');
text(s1,'headline-line1','把一次实验',56,123,440,63,49,{serif:true});
text(s1,'headline-line2','完整地放在一起',56,188,460,63,49,{serif:true});
text(s1,'value-student','学生顺手记录',59,306,410,38,29,{bold:true});
text(s1,'value-mentor','导师看清过程',59,349,410,38,29,{bold:true});
text(s1,'support-line1','实验条件、现场变化、原始资料',59,433,430,35,23,{color:color.muted});
text(s1,'support-line2','放在同一次实验里。',59,468,420,35,23,{color:color.muted});
text(s1,'continuity','今天接着做，明天接着记。',59,568,440,34,23,{color:color.accent});
s1.speakerNotes.text=`这一页用一个场景介绍 SmartLab。学生不是先找一张空白表格，而是从正在做的实验进入，把条件、现场变化和原始资料集中在同一个实验中。同一条实验可以跨天继续，导师查看时也能看到上下文。讲解时停留在三个日常问题：我做到哪里了，改了什么，原始图片在哪里。\n\n内容依据：SMARTLAB_V3_CONTEXT.md（产品定位、学生工作台、跨天实验章节），本项目 WB 预览记录页。\n插画由内置图像生成工具原创，场景及条带为示意，不表示科研结果。\n视觉参考：Google NotebookLM 公开样例的插画叙事和知识讲义构图，未使用其图片素材。${source}\n另参考公开 NotebookLM 家族史演示截图的纸感及场景化构图：${inspiration}`;
const s2=await page('02-conditions-story.png',2,'少一些重复填写');
text(s2,'headline','选好抗体，条件先有一份底稿',56,86,1168,65,45,{serif:true});
text(s2,'subtitle','以 WB 记录为例',59,152,350,33,23,{color:color.muted});
const captions=[
 [82,1,'选中本次抗体','说明书参考信息随选择带入'],
 [482,2,'沿用常用条件','实验室方案作为这次的起点'],
 [888,3,'记录本次变化','按实际操作调整并确认']
];
for(const [x,n,title,body] of captions){
 text(s2,'step-number-'+n,String(n).padStart(2,'0'),x,570,48,33,25,{color:color.accent,serif:true});
 text(s2,'step-title-'+n,title,x+50,569,300,35,26,{bold:true});
 text(s2,'step-caption-'+n,body,x,613,340,31,21,{color:color.muted});
}
s2.speakerNotes.text=`这页解释系统如何减轻记录负担。演示时从耗材目录选择一支已整理参考信息的抗体；系统据其记录带入适用实验、种属、稀释等参考信息，并结合实验室已有 WB 方案。学生在本次实验里核对、调整实际操作和确认，无需每次重新查找和输入同一套信息。参考信息来源于说明书的已整理条目；本页不宣称系统能自动可靠识别任意厂家说明书。学生更换抗体或条件时，应以实际操作为准。\n\n内容依据：本项目 WB 抗体与实验室方案带入功能，docs/v3-wb-conditions-20261014；用户确认的“抗体耗材目录关联实验条件、减少重复填写”方向。\n插画为原创场景示意。视觉参考：${source}`;
const s3=await page('03-demo-workbench.png',3,'现场一起做');
text(s3,'headline','现在，一起记一次 WB',56,85,1168,63,45,{serif:true});
const actions=[
 ['01','打开已有实验','找到今天继续做的那一条'],
 ['02','选抗体，核对条件','沿用方案，调整本次差异'],
 ['03','补上一张原图','把操作记录和图片放在一起']
];
for(const [i,a] of actions.entries()){
 const y=221+i*120;
 text(s3,'action-number-'+i,a[0],57,y,58,40,33,{serif:true,color:color.accent});
 text(s3,'action-title-'+i,a[1],123,y+1,376,38,26,{bold:true});
 text(s3,'action-description-'+i,a[2],123,y+48,380,32,21,{color:color.muted});
}
// The real product screenshot is cropped inside the illustrated monitor frame.
await img(s3,'WB-current-preview.png',{left:566,top:171,width:563,height:316},'SmartLab 当前 WB 演示实验的真实记录界面',{crop:{left:0.176,top:0.073,right:0.025,bottom:0.441}});
text(s3,'demo-screen-caption','WB 演示 · 当前预览界面',830,115,365,25,15,{color:color.muted,align:'right'});
text(s3,'mentor-transition','完成后，切到导师视角看一遍。',57,606,470,34,22,{color:color.accent});
s3.speakerNotes.text=`转入现场实操。这一页展示项目现有 WB 预览实例的真实界面，图中实验明确标记为合成演示。讲者先打开同一条 WB 实验，演示抗体选择、查看带入的条件、修改一处实际条件、补充一张演示原图；随后切到导师视角，展示怎样查看实验内容和给出反馈。用现场地址进入系统，按课前准备的演示账号操作。不要把个人凭据放进投影。\n\n截图来源：LabSmart/docs/ux-review-20261015/01-record-overview.png，2026-10-15。截图被嵌入原创插画显示器中，未改写系统文字。\n图片中的科研器具与背景为原创插画示意。视觉参考：${source}`;
await fs.mkdir(path.join(root,'.codex-finalizer'),{recursive:true});
for(const [i,s] of p.slides.items.entries()){
 const png=await p.export({slide:s,format:'png',scale:1.5});
 await fs.writeFile(path.join(root,'output',`SmartLab_样稿_${i+1}.png`),new Uint8Array(await png.arrayBuffer()));
 const l=await s.export({format:'layout'});await fs.writeFile(path.join(root,'.build',`slide-${i+1}.layout.json`),await l.text());
}
await (await PresentationFile.exportPptx(p)).save(path.join(root,'.codex-finalizer','candidate.pptx'));
console.log('Wrote three-slide draft and previews');
