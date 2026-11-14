(function () {
  'use strict';
  function parseCsv(text) {
    var rows = [], row = [], value = '', quoted = false;
    text = text.replace(/^\uFEFF/, '');
    for (var i = 0; i < text.length; i++) {
      var c = text[i];
      if (c === '"') { if (quoted && text[i + 1] === '"') { value += '"'; i++; } else { quoted = !quoted; } }
      else if (c === ',' && !quoted) { row.push(value); value = ''; }
      else if ((c === '\n' || c === '\r') && !quoted) { row.push(value); if (row.some(function (v) { return v.trim() !== ''; })) rows.push(row); row = []; value = ''; if (c === '\r' && text[i + 1] === '\n') i++; }
      else value += c;
    }
    if (quoted) throw new Error('CSV 引号没有闭合，请检查原文件。');
    row.push(value); if (row.some(function (v) { return v.trim() !== ''; })) rows.push(row);
    return rows;
  }
  function init() {
    var pendingForms = [];
    var uploadTarget = null, uploadBefore = new Set(), uploadNames = new Set(), openingStepUpload = false, evidenceTimer;
    async function refreshEvidence() {
      var firstForm = document.querySelector('[data-bench-form]'); if (!firstForm) return;
      var response = await fetch(firstForm.action.split('#')[0] + '&bench_evidence=1', {credentials:'same-origin'});
      if (!response.ok) throw new Error('附件列表未刷新，请稍后重试。');
      var result = await response.json();
      document.querySelectorAll('.bench-evidence').forEach(function (box) {
        var form = box.closest('form'), selected = new Set(Array.from(box.querySelectorAll('input:checked')).map(function (i) { return Number(i.value); }));
        box.querySelectorAll('.bench-file-option,p').forEach(function (node) { node.remove(); });
        result.files.forEach(function (file) {
          var label = document.createElement('label'), checkbox = document.createElement('input'); label.className = 'bench-file-option'; checkbox.type = 'checkbox'; checkbox.name = 'evidence_ids[]'; checkbox.value = file.id;
          checkbox.checked = selected.has(file.id) || (form === uploadTarget && !uploadBefore.has(file.id) && file.own_upload && uploadNames.has(file.name));
          label.appendChild(checkbox); label.appendChild(document.createTextNode(' ' + file.name)); box.insertBefore(label,box.querySelector('button'));
        });
        box.querySelector('summary').textContent = '关联本步照片或原始文件（' + box.querySelectorAll('input:checked').length + '）';
        if (form === uploadTarget && result.files.some(function (file) { return !uploadBefore.has(file.id) && file.own_upload && uploadNames.has(file.name); })) { form.dispatchEvent(new Event('input',{bubbles:true})); }
      });
      uploadBefore = new Set(result.files.map(function (file) { return file.id; }));
    }
    async function flushDrafts() { for (var entry of pendingForms) await entry.flush(); }
    document.querySelectorAll('[data-bench-form]').forEach(function (form) {
      var status = form.querySelector('[data-bench-save-status]'), timer, queue = Promise.resolve(), conflict = false, dirty = false, sequence = 0, busy = false;
      function save(state) {
        queue = queue.catch(function () {}).then(async function () {
          if (conflict) throw new Error('记录冲突，请刷新后重新编辑。');
          var data = new FormData(form); data.set('bench_ajax', '1'); data.set('record_state', state);
          var savingSequence = sequence; busy = true;
          status.textContent = '正在保存…';
          var response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' });
          var result;
          try { result = await response.json(); } catch (_) { throw new Error('未能保存，请检查连接或重新登录。当前输入仍保留在页面。'); }
          if (!response.ok || !result.ok) { if ((result.message || '').indexOf('其他页面更新') !== -1) conflict = true; throw new Error(result.message || '保存失败，当前输入仍保留在页面。'); }
          form.elements.expected_revision.value = result.revision;
          if (sequence === savingSequence) dirty = false;
          if (form.hasAttribute('data-wb-light') && sequence === savingSequence) {
            var card = form.closest('[data-rec-stage-card]'), badge = card && card.querySelector('.rec-stage-state .pv-state');
            if (badge) { badge.className = 'pv-state ' + (result.state === 'confirmed' ? 'is-confirmed' : 'is-default'); badge.textContent = result.state === 'confirmed' ? '已确认' : '草稿待确认'; }
          }
          status.textContent = result.state === 'confirmed' ? (state === 'confirmed' ? '已确认并保存' : '补充已保存，实际条件保持已确认') : '草稿已保存，实际操作待确认';
          return result;
        });
        return queue.catch(function (error) { status.textContent = error.message; throw error; }).finally(function () { busy = false; });
      }
      pendingForms.push({pending: function () { return dirty || busy; }, flush: async function () { clearTimeout(timer); if (dirty) await save('draft'); else await queue; }});
      function draft() { dirty = true; sequence++; clearTimeout(timer); status.textContent = '有修改，等待保存…'; timer = setTimeout(function () { save('draft').catch(function () {}); }, 1500); }
      form.addEventListener('input', draft);
      form.addEventListener('change', function (event) {
        var input = event.target;
        if (input.dataset.benchSource === 'sample' && input.value && form.elements.sample_label) { form.elements.sample_label.value = input.selectedOptions[0].textContent.trim(); if (form.hasAttribute('data-wb-light')) form.elements.sample_label.disabled = false; }
        if (input.dataset.benchSource === 'lot' && input.value) {
          var destination = {primary_lot_id: 'primary', antibody_lot_id: 'antibody', kit_lot_id: 'kit'}[input.name];
          if (destination && form.elements[destination]) { form.elements[destination].value = input.selectedOptions[0].textContent.split(' · ')[0].trim(); if (form.hasAttribute('data-wb-light')) form.elements[destination].disabled = false; }
        }
        draft();
      });
      form.addEventListener('submit', async function (event) {
        event.preventDefault(); clearTimeout(timer);
        var state = event.submitter && event.submitter.value === 'confirmed' ? 'confirmed' : 'draft';
        var buttons = Array.from(form.querySelectorAll('button[type=submit]')); buttons.forEach(function (b) { b.disabled = true; });
        try { await save(state); if (state === 'confirmed') { await flushDrafts(); window.location.hash = form.dataset.benchNext ? 'bench-step-' + form.dataset.benchNext : 'bench-' + form.dataset.benchKey.replace('.', '-'); window.location.reload(); } }
        catch (_) {} finally { buttons.forEach(function (b) { b.disabled = false; }); }
      });
      form.querySelectorAll('[data-bench-use-plan]').forEach(function (button) { button.addEventListener('click', function () { var input = document.getElementById(button.dataset.benchUsePlan); input.value = input.dataset.benchPlanned; input.dispatchEvent(new Event('input', { bubbles: true })); }); });
      form.querySelectorAll('[data-bench-upload]').forEach(function (button) { button.addEventListener('click', function () {
        uploadTarget = form; uploadNames = new Set(); uploadBefore = new Set(Array.from(form.querySelectorAll('[name="evidence_ids[]"]')).map(function (input) { return Number(input.value); }));
        ['filesDiv','uploadsDiv'].forEach(function (id) { var panel=document.getElementById(id); if(panel)panel.hidden=false; });
        var uploader=document.querySelector('[data-smartlab-open-upload-dialog]'); if(uploader){ openingStepUpload=true; uploader.click(); openingStepUpload=false; }
      }); });
      form.querySelectorAll('[data-bench-refresh-evidence]').forEach(function (button) { button.addEventListener('click', function () { refreshEvidence().catch(function (e) { status.textContent=e.message; }); }); });
      var tbody = form.querySelector('[data-bench-rows]');
      if (!tbody) return;
      var keys = ['sample', 'group', 'indicator', 'value', 'unit'], labels = ['样本', '分组', '指标', '原始数值', '单位'];
      function renumber() { Array.from(tbody.rows).forEach(function (row, index) { Array.from(row.querySelectorAll('input')).forEach(function (input, column) { input.name = 'result_rows[' + index + '][' + keys[column] + ']'; }); }); }
      function addRow(values) {
        if (tbody.rows.length >= 200) throw new Error('最多录入 200 行，更多数据请作为原始文件上传。');
        var row = document.createElement('tr');
        keys.forEach(function (_, column) { var cell = document.createElement('td'), input = document.createElement('input'); input.className = 'form-control'; input.maxLength = 200; input.setAttribute('aria-label', labels[column]); input.value = values ? values[column] || '' : ''; cell.appendChild(input); row.appendChild(cell); });
        var cell = document.createElement('td'), button = document.createElement('button'); button.type = 'button'; button.textContent = '移除'; button.dataset.benchRemoveRow = ''; button.setAttribute('aria-label', '移除此结果行'); cell.appendChild(button); row.appendChild(cell); tbody.appendChild(row); renumber();
      }
      form.querySelector('[data-bench-add-row]').addEventListener('click', function () { try { addRow(); draft(); } catch (e) { status.textContent = e.message; } });
      tbody.addEventListener('click', function (event) { if (event.target.matches('[data-bench-remove-row]')) { event.target.closest('tr').remove(); renumber(); draft(); } });
      var csvInput = form.querySelector('[data-bench-csv]'), importStatus = form.querySelector('[data-bench-import-status]');
      csvInput.addEventListener('change', async function () {
        try {
          var file = csvInput.files[0]; if (!file) return;
          if (file.size > 1024 * 1024) throw new Error('请导入 1 MB 以内的 CSV，较大的表可作为原始文件上传。');
          var rows = parseCsv(await file.text()); if (rows.length < 2) throw new Error('CSV 需要列名和至少一行结果。');
          var aliases = [['样本', 'sample'], ['分组', 'group'], ['指标', 'indicator'], ['数值', 'value', '原始数值'], ['单位', 'unit']];
          var header = rows.shift().map(function (v) { return v.trim().toLowerCase(); });
          var columns = aliases.map(function (names) { return header.findIndex(function (name) { return names.indexOf(name) !== -1; }); });
          if (columns.some(function (index) { return index < 0; }) || new Set(header).size !== header.length || aliases.some(function (names) { return header.filter(function (name) { return names.indexOf(name) !== -1; }).length !== 1; })) throw new Error('列名需要包含：样本、分组、指标、数值、单位，且不能重复。');
          if (rows.length + tbody.rows.length > 200) throw new Error('结果表合计最多 200 行；请上传原始文件保存完整数据。');
          var mapped = rows.map(function (row) { if (row.length !== header.length) throw new Error('CSV 某行列数与列名不一致，请检查原文件。'); var values = columns.map(function (column) { return row[column]; }); if (values.some(function (value) { return value.length > 200; })) throw new Error('CSV 某字段超过 200 个字符，请检查原文件。'); return values; });
          mapped.forEach(addRow); importStatus.textContent = '已追加导入 ' + mapped.length + ' 行，草稿会保存；请核对数值并关联原文件后确认结果。'; draft();
        } catch (e) { importStatus.textContent = e.message; } finally { csvInput.value = ''; }
      });
    });
    var reviewForm = document.querySelector('[data-bench-submit]');
    if (reviewForm) reviewForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      var button = reviewForm.querySelector('button[type=submit]'), status = reviewForm.querySelector('[data-bench-submit-status]');
      button.disabled = true; status.textContent = '正在保存当前记录并提交…';
      try {
        await flushDrafts();
        var data = new FormData(reviewForm); data.set('bench_submit_ajax', '1');
        var response = await fetch(reviewForm.action, {method:'POST',body:data,credentials:'same-origin'});
        var result;
        try { result = await response.json(); } catch (_) { throw new Error('未能提交，请检查连接或重新登录。当前输入仍保留在页面。'); }
        if (!response.ok || !result.ok) throw new Error(result.message || '提交失败，请检查记录。');
        window.location.href = result.redirect;
      } catch (error) {
        status.textContent = error.message;
        var checklist = document.querySelector('.bench-checklist'); if (checklist) checklist.open = true;
      } finally { button.disabled = false; }
    });
    window.addEventListener('beforeunload', function (event) { if (pendingForms.some(function (entry) { return entry.pending(); })) { event.preventDefault(); event.returnValue = ''; } });
    document.addEventListener('smartlab-upload-complete', function (event) {
      if (!uploadTarget) return;
      event.preventDefault();
      var target = uploadTarget;
      uploadNames = new Set(event.detail.filenames);
      refreshEvidence().then(flushDrafts).then(function () {
        window.location.hash = 'bench-' + target.dataset.benchKey.replace('.', '-');
        window.location.reload();
      }).catch(function (error) {
        target.querySelector('[data-bench-save-status]').textContent = '文件已上传，步骤关联未保存：' + error.message + ' 请刷新附件列表后重新保存。';
      });
    });
    var filesPanel=document.getElementById('filesDiv');
    document.addEventListener('change',function(event){if(uploadTarget && event.target.type==='file' && !event.target.matches('[data-bench-csv]')) uploadNames=new Set(Array.from(event.target.files||[]).map(function(file){return file.name;}));},true);
    var globalUploader=document.querySelector('[data-smartlab-open-upload-dialog]');
    if(globalUploader)globalUploader.addEventListener('click',function(){if(!openingStepUpload){uploadTarget=null;uploadNames=new Set();}});
    if(filesPanel)filesPanel.addEventListener('dragenter',function(){uploadTarget=null;uploadNames=new Set();});
    if (filesPanel && pendingForms.length) new MutationObserver(function () { clearTimeout(evidenceTimer); evidenceTimer=setTimeout(function () { refreshEvidence().catch(function () {}); },500); }).observe(filesPanel,{childList:true,subtree:true});
    function revealHash() { var target = document.getElementById(location.hash.slice(1)); if (target && target.tagName === 'DETAILS') target.open = true; }
    revealHash(); window.addEventListener('hashchange', revealHash);
    var root = document.getElementById('bench-notebook');
    if (root) document.querySelectorAll('.ls-record-tabs a').forEach(function (link) { link.addEventListener('click', function () { var target = document.getElementById(link.hash.slice(1)); if (target && target.tagName === 'DETAILS') target.open = true; }); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
