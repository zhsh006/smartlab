
(() => {
  const root = document.getElementById('sl-student-flow');
  const content = root.querySelector('#sl-content');
  const title = root.querySelector('#sl-view-title');
  const live = root.querySelector('#sl-live');
  const experiments = {
    wb:{kind:'WB',name:'蛋白表达验证',id:'WB-1012',status:'待复核',purpose:'比较不同处理组的蛋白表达',protocol:'WB 方案 v2',mapping:'泳道表：样本 S01–S06，对照 / LPS 组',detail:'制样、转膜、曝光在同一批次中持续记录',fields:'泳道与样本、抗体批号、曝光文件',raw:[['WB1012_full.tif','完整曝光原图'],['WB1012_lanes.xlsx','泳道与样本对应表']],figure:'WB_整理图_v2.pdf',figureMeta:'学生上传 · 原图和样本表已关联',notes:[['10.10 · 制样','样本编号与上样顺序已登记。'],['10.11 · 转膜','沿用 WB 方案 v2，本次变动记在批次内。'],['10.12 · 曝光','完整曝光原图已归档，待复核条带与样本的对应关系。']]},
    pcr:{kind:'qPCR',name:'基因表达验证',id:'PCR-1013',status:'数据整理中',purpose:'验证处理后的相关基因表达',protocol:'qPCR 方案 v1',mapping:'板位表：样本、靶基因及内参对应关系',detail:'Ct 原始表已归档，统计图已上传',fields:'板位、靶基因与内参、Ct 原始表',raw:[['PCR1013_Ct.csv','仪器导出的原始 Ct 表'],['PCR1013_plate.xlsx','板位、样本与基因对应表']],figure:'qPCR_统计图_v1.png',figureMeta:'学生上传 · Ct 表和分析过程已关联',notes:[['10.12 · 准备','本次样本和板位沿用已确认的计划。'],['10.13 · 数据整理','Ct 原始表与统计图分别归档，分析说明待补充。']]},
    if:{kind:'IF',name:'免疫荧光观察',id:'IF-1013',status:'结果待整理',purpose:'观察处理后细胞的染色与形态',protocol:'IF 方案 v1',mapping:'孔位、样本编号、染色组合对应表',detail:'视野原图与采集参数已记录，整理图待上传',fields:'孔位与视野、染色组合、采集参数',raw:[['IF1013_field01.czi','完整视野与通道原始文件'],['IF1013_acquisition.xlsx','样本、视野与采集参数']],figure:null,figureMeta:'待学生整理上传',notes:[['10.12 · 染色','按本次批次记录样本与染色组合。'],['10.13 · 采集','视野原图已归档，代表图与标注尚未整理。']]}
  };
  let view = 'plan';
  let selectedExperiment = 'wb';
  let selectedFigures = new Set(['wb']);
  let reportCycle = 'two';
  let draftReady = false;
  let reportText = '';
  let addedFeedback = false;
  let sourceOpen = null;
  const escape = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const msg = text => { live.textContent = text; };
  const button = (label,attrs='',extraClass='') => `<button type="button" class="${extraClass} cursor-interaction" ${attrs}>${label}</button>`;
  function nav(next) {
    const note = root.querySelector('#sl-report-text');
    if(note) reportText = note.value;
    view = next;
    sourceOpen = null;
    live.textContent = '';
    render();
  }
  function plan() {
    return `<div class="sl-heading"><h2>我的实验</h2><p class="sl-subtle">近期计划 · 炎症模型验证</p></div>
      ${Object.entries(experiments).map(([key,e])=>`<article class="sl-task"><span class="sl-task-icon">${e.kind}</span><div class="sl-task-text"><h3>${e.name}<span class="sl-status">${e.status}</span></h3><p class="sl-small sl-subtle">${e.detail}</p><p class="sl-small sl-subtle">${e.id} · ${e.protocol}</p></div>${button('继续记录',`data-action="open-record" data-key="${key}"`)}</article>`).join('')}
      ${addedFeedback?`<article class="sl-task"><span class="sl-task-icon">复核</span><div class="sl-task-text"><h3>补充样本对应说明<span class="sl-status">下一轮计划</span></h3><p class="sl-small sl-subtle">来自导师对本次汇报的反馈 · 关联 WB-1012</p></div>${button('打开批次','data-action="open-record" data-key="wb"')}</article>`:''}
      <div class="sl-actions">${button('去整理本次汇报','data-action="goto-report"')}</div>`;
  }
  function record() {
    const e = experiments[selectedExperiment];
    return `<div class="sl-pills" aria-label="选择实验类型">${Object.entries(experiments).map(([key,item])=>button(item.kind,`data-action="choose-record" data-key="${key}" aria-pressed="${key===selectedExperiment}"`)).join('')}</div>
      <div class="sl-heading"><h2>${e.name}</h2><p class="sl-subtle">${e.id} · ${e.status}</p></div>
      <div class="sl-two-col"><section class="sl-panel"><div class="sl-section-title"><h3>本次实验</h3><span class="sl-small sl-subtle">沿用已确认的计划</span></div>
        <dl class="sl-kv"><dt>实验目的</dt><dd>${e.purpose}</dd><dt>方案版本</dt><dd>${e.protocol}</dd><dt>样本对应</dt><dd>${e.mapping}</dd><dt>关联信息</dt><dd>${e.fields}</dd></dl>
        <label class="sl-field" for="sl-stage"><span>当前进度</span><select id="sl-stage"><option ${e.status==='待复核'?'selected':''}>待复核</option><option ${e.status==='数据整理中'?'selected':''}>数据整理中</option><option ${e.status==='结果待整理'?'selected':''}>结果待整理</option><option ${e.status==='实验进行中'?'selected':''}>实验进行中</option><option ${e.status==='已完成'?'selected':''}>已完成</option><option ${e.status==='需要重做'?'selected':''}>需要重做</option></select></label>
        <label class="sl-field" for="sl-record-mode"><span>记录来源</span><select id="sl-record-mode"><option value="digital">直接补充记录</option><option value="paper">纸页照片补录</option></select></label>
        <label class="sl-field" id="sl-paper-field" for="sl-paper-file" hidden><span>选择实验本照片</span><input id="sl-paper-file" type="file" accept="image/*"></label>
        <label class="sl-field" for="sl-note"><span>本次补充</span><textarea id="sl-note" placeholder="记下本次变化、异常或下一步；已有参数可沿用。"></textarea></label>
        <div class="sl-actions"><button type="button" class="sl-primary cursor-interaction" data-action="save-note">保存补充</button></div>
      </section><div><section class="sl-panel"><div class="sl-section-title"><h3>批次记录</h3></div><ol class="sl-timeline">${e.notes.map(([time,text])=>`<li><time>${escape(time)}</time><p>${escape(text)}</p></li>`).join('')}</ol></section>
      <section class="sl-panel"><div class="sl-section-title"><h3>文件与整理图</h3></div><div>${e.raw.map(([name,desc])=>`<div class="sl-file"><span class="sl-file-icon">${escape(name.split('.').pop().toUpperCase())}</span><div class="sl-file-info">${escape(name)}<span class="sl-small sl-subtle">${escape(desc)} · 原始资料</span></div></div>`).join('')}</div>
        <div class="sl-file"><span class="sl-file-icon">图</span><div class="sl-file-info">${e.figure||'整理图待上传'}<span class="sl-small sl-subtle">${e.figureMeta}</span>${e.figure?button('查看关联来源',`data-action="source" data-key="${selectedExperiment}" aria-expanded="false" aria-controls="sl-record-source"`,'sl-link'):''}</div></div>
        <div id="sl-record-source" class="sl-linked" hidden><span class="sl-subtle">${e.figure} 的关联来源</span><p>${e.id} · ${e.protocol}</p>${e.raw.map(([name])=>`<p>${escape(name)}</p>`).join('')}</div>
      </section></div></div>`;
  }
  function report() {
    const selected = [...selectedFigures].filter(k=>experiments[k].figure);
    return `<div class="sl-heading"><h2>整理本次汇报</h2><p class="sl-subtle">选用自己整理好的图，附上结果与待讨论的问题</p></div>
      <div class="sl-two-col"><section class="sl-panel"><h3>本次内容</h3><label class="sl-field" for="sl-cycle"><span>汇报周期</span><select id="sl-cycle"><option value="one" ${reportCycle==='one'?'selected':''}>每周一次</option><option value="two" ${reportCycle==='two'?'selected':''}>每两周一次</option></select></label>
      <p class="sl-small sl-subtle" style="margin:13px 0">${reportCycle==='two'?'10.01–10.14':'10.08–10.14'} · 学生选择汇报内容</p>
      ${Object.entries(experiments).map(([key,e])=>`<label class="sl-check-row"><input type="checkbox" data-select-figure="${key}" ${selectedFigures.has(key)?'checked':''} ${e.figure?'':'disabled'}><span>${e.name}<span class="sl-small sl-subtle">${e.figure||'整理图待上传'} · ${e.id}</span></span></label>`).join('')}
      <div class="sl-actions"><button type="button" class="sl-primary cursor-interaction" data-action="build-report" ${selected.length?'':'disabled'}>整理汇报草稿</button></div></section>
      <section class="sl-panel"><div class="sl-section-title"><h3>本次汇报</h3><span class="sl-status">${draftReady?'草稿待确认':'待整理'}</span></div>
      ${draftReady?`<div><p class="sl-small sl-subtle">实验与汇报图</p><ul>${selected.map(key=>`<li>${experiments[key].name}<br><span class="sl-subtle">${experiments[key].figure}</span> ${button('关联来源',`data-action="report-source" data-key="${key}"`,'sl-link')}</li>`).join('')}</ul>${sourceOpen&&experiments[sourceOpen]?`<div class="sl-linked">${experiments[sourceOpen].id} · ${experiments[sourceOpen].raw.map(([n])=>escape(n)).join('、')}</div>`:''}
      <label class="sl-field" for="sl-report-text"><span>结果与待讨论的问题</span><textarea id="sl-report-text" placeholder="写下你对结果的判断、仍不确定的地方和希望讨论的问题。">${escape(reportText)}</textarea></label><div class="sl-actions">${button('保存汇报草稿','data-action="save-report"')}${button('查看导师反馈','data-action="goto-feedback"')}</div></div>`:`<div class="sl-empty">选择本次要汇报的整理图。</div>`}
      </section></div>`;
  }
  function feedback() {
    return `<div class="sl-heading"><h2>导师反馈</h2><p class="sl-subtle">本次汇报 · 样本与整理图的对应关系</p></div>
      <article class="sl-feedback"><h3>补充图的来源说明</h3><p class="sl-meta">导师 · 关联 WB-1012 / WB_整理图_v2.pdf</p><p>汇报中补上整理图对应的样本编号，并注明使用的图版本。需要复核的部分可以先列作待讨论问题。</p>
      <div class="sl-actions"><button type="button" class="sl-primary cursor-interaction" data-action="add-feedback" ${addedFeedback?'disabled':''}>${addedFeedback?'已加入下一轮计划':'加入下一轮计划'}</button>${button('查看对应实验','data-action="open-record" data-key="wb"')}</div></article>
      ${addedFeedback?`<article class="sl-feedback"><h3>下一轮计划</h3><p>补充样本对应说明 · 关联 WB-1012</p><div class="sl-actions">${button('回到实验计划','data-action="goto-plan"')}</div></article>`:''}`;
  }
  function render() {
    const names = {plan:'实验计划',record:'实验记录',report:'整理汇报',feedback:'导师反馈'};
    title.textContent = names[view];
    root.querySelectorAll('[data-view]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.view===view)));
    content.innerHTML = ({plan,record,report,feedback})[view]();
    if(typeof lucide !== 'undefined') lucide.createIcons({attrs:{width:16,height:16}});
  }
  root.addEventListener('click',event=>{
    const control = event.target.closest('button');
    if(!control||!root.contains(control)||control.disabled)return;
    if(control.dataset.view){nav(control.dataset.view);return;}
    const action = control.dataset.action;
    const key = control.dataset.key;
    if(action==='open-record'){selectedExperiment=key;nav('record');}
    if(action==='choose-record'){selectedExperiment=key;sourceOpen=null;render();}
    if(action==='goto-report')nav('report');
    if(action==='goto-feedback')nav('feedback');
    if(action==='goto-plan')nav('plan');
    if(action==='save-note'){
      const e=experiments[selectedExperiment];
      const note=root.querySelector('#sl-note').value.trim();
      const stage=root.querySelector('#sl-stage').value;
      const paperMode=root.querySelector('#sl-record-mode').value==='paper';
      const photo=root.querySelector('#sl-paper-file').files[0];
      if(paperMode&&!photo){msg('请选择要补录的纸页照片。');return;}
      if(!note&&!photo&&stage===e.status){msg('补充一条记录，或更新当前进度。');return;}
      e.status=stage;
      if(photo)e.raw.push([photo.name,'实验本纸页照片 · 待核对']);
      e.notes.push(['刚刚 · '+(paperMode?'纸页补录':'补充记录'),note||(photo?'纸页照片已加入本批次，文字待核对。':'当前进度更新为：'+stage)]);
      render();msg('已加入本批次记录。');
    }
    if(action==='source'){sourceOpen=sourceOpen===key?null:key;root.querySelector('#sl-record-source').hidden=sourceOpen!==key;control.setAttribute('aria-expanded',String(sourceOpen===key));}
    if(action==='report-source'){
      const field=root.querySelector('#sl-report-text');if(field)reportText=field.value;
      sourceOpen=sourceOpen===key?null:key;render();
    }
    if(action==='build-report'){draftReady=true;render();msg('草稿已整理，结果与判断由学生补充确认。');}
    if(action==='save-report'){reportText=root.querySelector('#sl-report-text').value;msg('汇报草稿已保存。');}
    if(action==='add-feedback'){addedFeedback=true;render();msg('已关联到下一轮实验计划。');}
  });
  root.addEventListener('change',event=>{
    const target=event.target;
    if(target.id==='sl-record-mode')root.querySelector('#sl-paper-field').hidden=target.value!=='paper';
    if(target.id==='sl-cycle'){
      const field=root.querySelector('#sl-report-text');if(field)reportText=field.value;
      reportCycle=target.value;render();
    }
    if(target.dataset.selectFigure){
      const field=root.querySelector('#sl-report-text');if(field)reportText=field.value;
      target.checked?selectedFigures.add(target.dataset.selectFigure):selectedFigures.delete(target.dataset.selectFigure);
      draftReady=false;sourceOpen=null;render();
    }
  });
  render();
})();
