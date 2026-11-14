(function () {
  'use strict';
  var root = document.querySelector('[data-wb-quick]'); if (!root) return;
  var setup = root.querySelector('[data-wb-setup]'), actual = root.querySelector('[data-wb-actual]'), progress = root.querySelector('[data-wb-progress]');
  var queue = Promise.resolve(), timer, dirty = false, busy = false, conflict = false, sequence = 0;
  async function post(form, state, captured) {
    if (conflict) throw new Error('内容已在其他页面更新，请刷新后核对。');
    var data = captured || new FormData(form); if (state) data.set('record_state',state);
    var response = await fetch(form.action,{method:'POST',body:data,credentials:'same-origin'}), result;
    try { result = await response.json(); } catch (_) { throw new Error('未能保存，请检查连接或重新登录；输入仍保留。'); }
    if (!response.ok || !result.ok) { if (/更新|刷新/.test(result.message || '')) conflict = true; throw new Error(result.message || '保存失败。'); }
    form.elements.expected_revision.value = result.revision; return result;
  }
  var status = actual.querySelector('[data-wb-actual-status]');
  function saveActual(state) {
    var savingSequence = sequence; busy = true;
    queue = queue.catch(function () {}).then(async function () {
      status.textContent = '正在保存…'; var result = await post(actual,state);
      if (sequence === savingSequence) dirty = false;
      status.textContent = result.state === 'confirmed' ? '实际条件已确认；补充说明已保存。' : '草稿已保存，完成时统一确认。';
      var calc = root.querySelector('[data-wb-loading-result]');
      if (calc) { calc.replaceChildren(); (result.loading_calculation || []).forEach(function (row) { var line=document.createElement('p'); line.textContent=row.sample + '：' + (row.volume_ul === null ? '缺少明确的质量、浓度或单位，暂未计算' : '建议 ' + row.volume_ul + ' μL 原样本'); calc.appendChild(line); }); }
      var badge = root.querySelector('[data-wb-batch-state]'); badge.textContent = result.state === 'confirmed' ? '本次条件已确认' : '变化待确认';
      badge.className = 'pv-state ' + (result.state === 'confirmed' ? 'is-confirmed' : 'is-default'); return result;
    }).catch(function (error) { status.textContent = error.message; throw error; }).finally(function () { busy = false; });
    return queue;
  }
  function draft() { dirty = true; sequence++; clearTimeout(timer); status.textContent = '正在等待保存…'; timer = setTimeout(function () { saveActual('draft').catch(function () {}); },1200); }
  async function flush() { clearTimeout(timer); if (dirty) await saveActual('draft'); else await queue; }
  if (setup) {
    var setupStatus = setup.querySelector('[data-wb-setup-status]');
    async function selectionChanged() {
      if (setup.dataset.saving === '1') return;
      setup.dataset.saving = '1'; var captured = new FormData(setup);
      setupStatus.textContent = '正在带入条件…';
      setup.querySelectorAll('select,button').forEach(function (input) { input.disabled = true; });
      try { await flush(); await post(setup, null, captured); location.reload(); }
      catch (error) { setupStatus.textContent = error.message; }
      finally { setup.dataset.saving = '0'; setup.querySelectorAll('select,button').forEach(function (input) { input.disabled = false; }); }
    }
    setup.addEventListener('change',selectionChanged);
    setup.addEventListener('submit',function (event) { event.preventDefault(); });
    setup.querySelector('[data-wb-add-antibody]').addEventListener('click',function () {
      var host = setup.querySelector('[data-wb-antibody-picks]'); if (host.children.length >= 12) return;
      var row = document.createElement('div'); row.className = 'wb-antibody-pick';
      var select = host.querySelector('select').cloneNode(true); select.value = ''; select.removeAttribute('id'); select.setAttribute('aria-label','另一个一抗');
      var remove = document.createElement('button'); remove.type='button'; remove.className='btn btn-outline-secondary'; remove.dataset.wbRemoveAntibody=''; remove.textContent='移除'; row.append(select,remove); host.append(row); select.focus();
    });
    setup.addEventListener('click',function (event) { var remove = event.target.closest('[data-wb-remove-antibody]'); if (remove) { remove.closest('.wb-antibody-pick').remove(); selectionChanged(); } });
  }
  if (progress) progress.addEventListener('change',async function () { var status = progress.querySelector('[data-wb-progress-status]'), select = progress.querySelector('select'), captured = new FormData(progress); select.disabled=true; status.textContent='正在保存进展…'; try { await post(progress,null,captured); status.textContent='进展已保存，跨天继续'; } catch (error) { status.textContent=error.message; } finally { select.disabled=false; } });
  if (root.querySelector('[data-wb-show-changes]')) root.querySelector('[data-wb-show-changes]').addEventListener('click',function () { var details=root.querySelector('#wb-conditions-detail'); details.open=true; details.scrollIntoView({block:'nearest',behavior:'smooth'}); });
  var detailLink=root.querySelector('a[href="#wb-conditions-detail"]');
  if (detailLink) detailLink.addEventListener('click',function (event) { event.preventDefault(); var details=root.querySelector('#wb-conditions-detail'); details.open=true; details.scrollIntoView({block:'nearest',behavior:'smooth'}); });
  root.querySelectorAll('[data-wb-change-condition]').forEach(function (button) { button.addEventListener('click',function () { var input = document.getElementById(button.getAttribute('aria-controls')); input.hidden=false; input.disabled=false; input.focus(); button.hidden=true; }); });
  actual.addEventListener('input',function (event) { var row=event.target.closest('.wb-quick-condition-row'); if (row) row.querySelector('[data-wb-condition-value]').textContent=event.target.value || '未记录（可后补）'; draft(); });
  actual.addEventListener('submit',async function (event) { event.preventDefault(); clearTimeout(timer); var button=event.submitter; if (button) button.disabled=true; try { await saveActual('confirmed'); location.reload(); } catch (_) {} finally { if (button) button.disabled=false; } });
  var upload = root.querySelector('[data-wb-quick-upload]');
  if (upload) upload.addEventListener('click',async function () { try { await flush(); var trigger=document.querySelector('[data-smartlab-open-upload-dialog]'); if (trigger) trigger.click(); } catch (error) { status.textContent=error.message; } });
  document.addEventListener('smartlab-upload-complete',function () { flush().catch(function (error) { status.textContent=error.message; }); });
  window.addEventListener('beforeunload',function (event) { if (dirty || busy) { event.preventDefault(); event.returnValue=''; } });
})();
