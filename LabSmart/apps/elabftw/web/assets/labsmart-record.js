/* SmartLab V1 快速记录页交互（页签、阶段切换、保存状态显示） */
(function () {
  'use strict';
  function qs(selector, el) { return (el || document).querySelector(selector); }
  function qsa(selector, el) { return Array.prototype.slice.call((el || document).querySelectorAll(selector)); }

  // Background edits belong with the archive; daily WB recording starts at its stage.
  if (qs('.rec-wb-light')) {
    var background = qs('#bench-plan'), archive = qs('#rec-panel-archive');
    if (background && archive) archive.insertBefore(background, archive.firstChild);
  }

  /* 页签：快速记录 / 原始资料 / 完整档案 */
  var tabs = qsa('[data-rec-tab]');
  function activateTab(key) {
    tabs.forEach(function (tab) { tab.classList.toggle('active', tab.getAttribute('data-rec-tab') === key); });
    qsa('.rec-panel').forEach(function (panel) { panel.hidden = panel.id !== 'rec-panel-' + key; });
  }
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function (event) {
      event.preventDefault();
      activateTab(tab.getAttribute('data-rec-tab'));
    });
  });
  qsa('[data-rec-tab-link]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      event.preventDefault();
      activateTab(link.getAttribute('data-rec-tab-link'));
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });
  var hash = (location.hash || '').replace('#', '');
  if (hash === 'archive' || hash === 'files') { activateTab(hash); }
  if (hash === 'bench-plan' && qs('.rec-wb-light')) { activateTab('archive'); }

  /* 阶段切换（进度条） */
  var stepButtons = qsa('[data-rec-stage]');
  function showStage(index) {
    stepButtons.forEach(function (button) {
      var active = parseInt(button.getAttribute('data-rec-stage'), 10) === index;
      button.classList.toggle('is-selected', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    qsa('[data-rec-stage-card]').forEach(function (card) {
      card.hidden = parseInt(card.getAttribute('data-rec-stage-card'), 10) !== index;
    });
  }
  stepButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      activateTab('record');
      var index = parseInt(button.getAttribute('data-rec-stage'), 10);
      showStage(index);
      var card = qs('[data-rec-stage-card="' + index + '"]');
      if (card) history.replaceState(null, '', '#' + card.id);
    });
  });
  var initialCard = qs('[data-rec-stage-card]:not([hidden])');
  if (initialCard) { showStage(parseInt(initialCard.getAttribute('data-rec-stage-card'), 10)); }
  /* 确认保存后页面会带回 #bench-step-xxx，恢复到对应阶段 */
  var stepMatch = /^bench-step-(.+)$/.exec(hash);
  if (stepMatch) {
    var target = document.getElementById('bench-step-' + stepMatch[1]);
    if (target && target.hasAttribute('data-rec-stage-card')) {
      activateTab('record');
      showStage(parseInt(target.getAttribute('data-rec-stage-card'), 10));
    }
  }

  /* WB: render references as a summary, enable only the fields the student edits. */
  qsa('[data-wb-light]').forEach(function (form) {
    var confirm = qs('[data-wb-confirm]', form);
    var status = qs('[data-bench-save-status]', form);
    var changed = false;
    function panel(key, open) {
      var target = qs('[data-wb-panel="' + key + '"]', form);
      if (target) target.hidden = !open;
      var toggle = qs('[data-wb-toggle="' + key + '"]', form);
      if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function openField(key) {
      var editor = qs('[data-wb-editor="' + key + '"]', form);
      if (!editor) return;
      editor.hidden = false;
      qsa('input,select,textarea', editor).forEach(function (input) { input.disabled = false; });
      var choice = qs('[data-wb-edit-field="' + key + '"]', form);
      if (choice) choice.setAttribute('aria-pressed', 'true');
      var summary = qs('[data-wb-summary="' + key + '"]', form);
      if (summary && !form.elements[key].value.trim()) summary.hidden = true;
    }
    function missing() { return qsa('[data-wb-required="1"]', form).filter(function (input) { return !input.value.trim(); }); }
    function update() {
      qsa('[data-wb-summary]', form).forEach(function (row) {
        var input = form.elements[row.dataset.wbSummary];
        if (!input || input.disabled) return;
        qs('[data-wb-value]', row).textContent = input.tagName === 'SELECT' && input.value ? input.selectedOptions[0].textContent : (input.value.trim() || '尚未确定');
        qs('[data-wb-source]', row).textContent = input.value.trim() ? '本次填写 · 待确认' : '需要补充';
      });
      var count = missing().length;
      confirm.textContent = count ? '补齐 ' + count + ' 项并完成' : changed ? '确认本步实际条件' : confirm.dataset.wbDone === '1' ? '确认保存本步' : form.dataset.benchKey === 'step.finish' ? '完成本次操作' : '按这些条件完成';
    }
    qsa('[data-wb-toggle]', form).forEach(function (button) {
      button.addEventListener('click', function () {
        panel(button.dataset.wbToggle, button.getAttribute('aria-expanded') !== 'true');
      });
    });
    qsa('[data-wb-edit-field]', form).forEach(function (button) {
      button.addEventListener('click', function () { openField(button.dataset.wbEditField); var input = form.elements[button.dataset.wbEditField]; if (input) input.focus(); });
    });
    function pendingCondition(event) {
      if (!event.target.closest('[data-wb-editor]')) return;
      changed = true; update();
      var badge = qs('.rec-stage-state .pv-state', form.closest('[data-rec-stage-card]'));
      if (badge) { badge.className = 'pv-state is-default'; badge.textContent = '改动待确认'; }
    }
    form.addEventListener('input', pendingCondition);
    form.addEventListener('change', pendingCondition);
    // Capture before the generic autosave submit handler: reveal missing values,
    // but never submit a normal completion with unknown critical conditions.
    form.addEventListener('submit', function (event) {
      if (!event.submitter || event.submitter.value !== 'confirmed') return;
      var unknown = missing();
      if (!unknown.length) return;
      event.preventDefault(); event.stopImmediatePropagation();
      panel('changes', true);
      unknown.forEach(function (input) { openField(input.name); });
      qs('[data-wb-edit-heading]', form).textContent = '只补充缺少的关键条件';
      status.textContent = '只需补充这 ' + unknown.length + ' 项，已带入的条件无需重填。';
      unknown[0].focus();
    }, true);
    if (form.dataset.benchKey === 'step.prepare' && missing().length) {
      panel('changes', true);
      qs('[data-wb-edit-heading]', form).textContent = '样本与分组只在本次开始时确定';
      missing().forEach(function (input) { openField(input.name); });
    }
  });

  /* 右上角保存状态镜像当前编辑区的保存提示 */
  var pillText = qs('#rec-save-state span');
  var pill = qs('#rec-save-state');
  if (pillText && pill) {
    qsa('[data-bench-save-status]').forEach(function (status) {
      new MutationObserver(function () {
        var card = status.closest('[data-rec-stage-card]');
        if (card && card.hidden) { return; }
        var text = status.textContent.trim();
        if (text === '') { return; }
        pillText.textContent = text;
        pill.classList.toggle('saving', text.indexOf('正在保存') !== -1 || text.indexOf('等待保存') !== -1);
      }).observe(status, { childList: true, characterData: true, subtree: true });
    });
  }

  /* 异常勾选：勾选常见异常项写入文本（文本框仍可自由编辑，两者双向同步） */
  var EXCEPTION_PRESETS = {
    western_blot: ['条带较弱', '背景偏高', '非特异条带', '上样损失', '转膜气泡'],
    qpcr: ['扩增异常', '熔解曲线双峰', '复孔离散'],
    if: ['背景偏高', '掉片', '信号弱'],
    ihc: ['背景偏高', '染色过浅'],
    elisa: ['复孔离散', '标准曲线异常']
  };
  var defaultPresets = ['背景偏高', '异常污染'];
  var WB_STEP_EXCEPTIONS = {
    prepare: ['样本不足', '样本混淆'],
    loading: ['上样损失', '电泳异常'],
    transfer: ['转膜气泡', '转膜不均'],
    antibody: ['孵育中断', '试剂不足'],
    imaging: ['条带较弱', '背景偏高', '非特异条带']
  };
  qsa('[data-bench-exception]').forEach(function (box) {
    var presets = WB_STEP_EXCEPTIONS[box.dataset.wbExceptionStep] || EXCEPTION_PRESETS[box.getAttribute('data-bench-exception')] || defaultPresets;
    var textarea = box.querySelector('textarea');
    var host = box.querySelector('.rec-exception-presets');
    if (!textarea || !host) { return; }
    function contains(label) { return textarea.value.indexOf(label) !== -1; }
    function applyToggle(label, checked) {
      if (checked && !contains(label)) {
        var current = textarea.value.trim();
        textarea.value = current ? current + '；' + label : label;
      } else if (!checked && contains(label)) {
        textarea.value = textarea.value.split('；').filter(function (part) {
          return part.trim() !== label;
        }).join('；');
      }
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }
    presets.forEach(function (label, index) {
      var option = document.createElement('label');
      option.className = 'rec-exception-option';
      var input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = contains(label);
      input.addEventListener('change', function () { applyToggle(label, input.checked); });
      option.appendChild(input);
      option.appendChild(document.createTextNode(label));
      host.appendChild(option);
    });
    textarea.addEventListener('input', function () {
      qsa('input[type=checkbox]', host).forEach(function (input, index) {
        input.checked = contains(presets[index]);
      });
    });
  });
})();
