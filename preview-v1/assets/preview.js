/* SmartLab V1 评审预览交互（演示用，非生产代码） */
(function () {
  'use strict';
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };

  /* 占位提示（评审版：未开发的功能点击后给出说明） */
  var pvToast = document.createElement('div');
  pvToast.className = 'pv-toast';
  document.body.appendChild(pvToast);
  var pvToastTimer = null;
  function pvShowToast(msg) {
    pvToast.textContent = msg;
    pvToast.classList.add('show');
    clearTimeout(pvToastTimer);
    pvToastTimer = setTimeout(function () { pvToast.classList.remove('show'); }, 2000);
  }

  /* 空链接不跳转 */
  $$('a[href="#"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      if (!a.hasAttribute('data-pv-tab')) {
        pvShowToast('评审演示版：此功能为占位，开发完成后可用');
      }
    });
  });

  /* 下拉菜单（铃铛 / 用户菜单） */
  $$('[data-pv-dropdown]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var menu = btn.parentElement.querySelector('.dropdown-menu');
      var wasOpen = menu && menu.classList.contains('show');
      $$('.dropdown-menu.show').forEach(function (m) { m.classList.remove('show'); });
      if (menu && !wasOpen) { menu.classList.add('show'); }
    });
  });
  document.addEventListener('click', function () {
    $$('.dropdown-menu.show').forEach(function (m) { m.classList.remove('show'); });
  });

  /* 实验内页签：快速记录 / 原始资料 / 完整档案 */
  var tabLinks = $$('[data-pv-tab]');
  function activateTab(key) {
    tabLinks.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-pv-tab') === key); });
    $$('.pv-panel').forEach(function (p) { p.hidden = p.id !== 'pv-panel-' + key; });
  }
  tabLinks.forEach(function (tab) {
    tab.addEventListener('click', function (e) {
      e.preventDefault();
      activateTab(tab.getAttribute('data-pv-tab'));
    });
  });
  if (tabLinks.length) {
    var hash = (location.hash || '').replace('#', '');
    if (hash && $('#pv-panel-' + hash)) { activateTab(hash); }
  }

  /* 保存状态 */
  var saveWrap = $('#pv-save-state');
  var resetTimer = null;
  function nowHM() {
    var d = new Date();
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
  }
  function setSave(html, cls) {
    if (!saveWrap) { return; }
    saveWrap.className = 'pv-save-state' + (cls ? ' ' + cls : '');
    saveWrap.innerHTML = html;
  }
  function flashSaved(prefix) {
    if (!saveWrap) { return; }
    clearTimeout(resetTimer);
    setSave('<i class="fas fa-spinner fa-spin"></i><span>保存中…</span>', 'saving');
    setTimeout(function () {
      setSave('<i class="fas fa-check"></i><span>' + prefix + ' · ' + nowHM() + '</span>', 'saved');
      resetTimer = setTimeout(function () {
        setSave('<i class="far fa-cloud"></i><span>草稿已保存到服务器 · ' + nowHM() + '</span>', '');
      }, 3200);
    }, 650);
  }

  /* 时间线 */
  var timeline = $('#pv-timeline');
  function tlPrepend(iconCls, icon, title, meta, note) {
    if (!timeline) { return; }
    var item = document.createElement('div');
    item.className = 'pv-tl-item';
    item.innerHTML =
      '<span class="pv-tl-icon ' + iconCls + '"><i class="' + icon + '"></i></span>' +
      '<div class="pv-tl-body">' +
        '<div class="pv-tl-title">' + title + '</div>' +
        '<div class="pv-tl-meta">' + meta + '</div>' +
        (note ? '<div class="pv-tl-note">“' + note + '”</div>' : '') +
      '</div>';
    timeline.insertBefore(item, timeline.firstChild);
  }

  /* —— 阶段数据（演示） —— */
  var STAGES = [
    { key: 'prep', label: '样本与制备', step: 'done', fields: [
      { label: '样品编号', value: 'HPC-001 / HPC-002 / HPC-003', plan: 'HPC-001 / HPC-002 / HPC-003', state: 'confirmed' },
      { label: '裂解与定量', value: 'RIPA 裂解 · BCA 定量', plan: 'RIPA 裂解 · BCA 定量', state: 'confirmed' }
    ] },
    { key: 'quant', label: '定量与上样', step: 'done', fields: [
      { label: '上样量', value: '25 μg', plan: '20 μg', state: 'overridden', reason: '本次样本浓度低，调整上样量', scope: 'HPC-002 泳道' },
      { label: 'Marker', value: 'PageRuler 26616 · 5 μL', plan: 'PageRuler 26616 · 5 μL', state: 'confirmed' }
    ] },
    { key: 'gel', label: '电泳', step: 'done', fields: [
      { label: '电压 / 时长', value: '120 V · 75 min', plan: '120 V · 75 min', state: 'confirmed' },
      { label: '胶浓度', value: '10%', plan: '10%', state: 'confirmed' }
    ] },
    { key: 'transfer', label: '转膜', step: 'done', badge: '含补录记录', fields: [
      { label: '转膜条件', value: '300 mA · 90 min', plan: '250 mA · 120 min', state: 'overridden', reason: '实验台记录后补录（发生时间：昨天 20:10）', retro: true },
      { label: '膜', value: 'PVDF 0.45 μm', plan: 'PVDF 0.45 μm', state: 'confirmed' }
    ] },
    { key: 'block', label: '封闭', step: 'done', fields: [
      { label: '封闭液 / 时长', value: '5% BSA · 室温 60 min', plan: '5% BSA · 室温 60 min', state: 'confirmed' }
    ] },
    { key: 'primary', label: '一抗', step: 'current', active: true, fields: [
      { label: '抗体', value: 'Anti-GAD67（Abcam ab26116）', plan: 'Anti-GAD67', state: 'default' },
      { label: '稀释比', value: '1:1000', plan: '1:1000', state: 'default' },
      { label: '孵育条件', value: '4 °C 过夜', plan: '室温 2 h', state: 'overridden', reason: '与上次结果对照，改为过夜孵育' }
    ] },
    { key: 'secondary', label: '二抗', step: 'next', fields: [] },
    { key: 'imaging', label: '成像', step: 'todo', fields: [] },
    { key: 'wrap', label: '补充 / 整理', step: 'todo', fields: [] }
  ];
  var currentStage = 5;

  var STATE_CHIP = {
    'default': { cls: 'is-default', text: '方案默认 · 未确认' },
    confirmed: { cls: 'is-confirmed', text: '实际按方案' },
    overridden: { cls: 'is-overridden', text: '本次已改' },
    unknown: { cls: 'is-unknown', text: '未记录' }
  };
  function chip(state) {
    var s = STATE_CHIP[state] || STATE_CHIP.unknown;
    return '<span class="pv-state ' + s.cls + '">' + s.text + '</span>';
  }

  var stagePills = $('#pv-stages');
  var stageHost = $('#pv-stage-fields');
  var stageTitle = $('#pv-stage-title');
  var stageHint = $('#pv-stage-hint');

  var STEP_LINE = { done: 'is-green', current: 'is-blue', next: 'is-lightblue', todo: 'is-gray' };
  function renderStagePills() {
    if (!stagePills) { return; }
    var last = STAGES.length - 1;
    stagePills.innerHTML = STAGES.map(function (s, i) {
      var state = s.step || 'todo';
      var check = (state === 'done' || state === 'current') ? '<i class="fas fa-check"></i>' : '';
      var left = i > 0
        ? '<span class="pv-step-line l ' + (STEP_LINE[STAGES[i - 1].step] || 'is-gray') + '"></span>'
        : '<span class="pv-step-line l is-hidden"></span>';
      var right = i < last
        ? '<span class="pv-step-line r ' + (STEP_LINE[state] || 'is-gray') + '"></span>'
        : '<span class="pv-step-line r is-hidden"></span>';
      return '<button type="button" class="pv-step is-' + state + (i === currentStage ? ' is-selected' : '') + '" data-stage="' + i + '" aria-label="' + s.label + '">' +
        '<span class="pv-step-track">' + left +
        '<span class="pv-step-dot">' + check + (s.badge ? '<span class="pv-step-badge" title="' + s.badge + '"></span>' : '') + '</span>' +
        right + '</span>' +
        '<span class="pv-step-label">' + s.label + '</span>' +
        '</button>';
    }).join('');
  }

  function renderFields() {
    if (!stageHost) { return; }
    var st = STAGES[currentStage];
    if (stageTitle) { stageTitle.textContent = st.label + (st.active ? '（当前阶段）' : ''); }
    if (stageHint) { stageHint.textContent = st.fields.length ? '只补本次变化和异常，其余条件保持“方案默认”' : '该阶段尚未开始'; }
    if (!st.fields.length) {
      stageHost.innerHTML = '<div class="pv-empty-stage"><i class="far fa-circle-play"></i>该阶段还没有记录。<br>可以现在补记，或继续其他阶段；未记录的信息保持“未知”，不影响继续使用。</div>';
      return;
    }
    stageHost.innerHTML = st.fields.map(function (f, i) {
      return '<div class="pv-param-row" data-idx="' + i + '">' +
        '<div class="pv-param-label">' + f.label + '</div>' +
        '<div class="pv-param-value">' +
          '<div class="pv-param-current">' + f.value + '</div>' +
          '<div class="pv-param-plan">方案默认：' + f.plan +
            (f.scope ? ' · 范围：' + f.scope : '') +
            (f.reason ? ' · ' + f.reason : '') +
          '</div>' +
        '</div>' +
        '<div class="pv-param-state">' + chip(f.state) + '</div>' +
        '<div class="pv-param-actions">' +
          (f.state === 'default' ? '<button type="button" class="bench-inline-action" data-pv-confirm>按此方案执行</button>' : '') +
          '<button type="button" class="bench-inline-action" data-pv-change>记录变化</button>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  function closeEditors() {
    $$('.pv-inline-editor').forEach(function (ed) { if (ed.parentElement) { ed.parentElement.removeChild(ed); } });
  }

  function openRowEditor(row, f) {
    closeEditors();
    var ed = document.createElement('div');
    ed.className = 'pv-inline-editor';
    ed.innerHTML =
      '<div class="pv-edit-grid">' +
        '<div><label>本次实际值</label><input class="form-control form-control-sm pv-edit-value"></div>' +
        '<div><label>原因（可选）</label><input class="form-control form-control-sm pv-edit-reason" placeholder="例如：本次样本浓度低"></div>' +
      '</div>' +
      '<div class="bench-form-actions">' +
        '<button type="button" class="btn btn-primary btn-sm" data-pv-save-change>保存记录</button>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" data-pv-cancel>取消</button>' +
        '<span class="smallgray">保存后追加一条变化记录，不覆盖历史。</span>' +
      '</div>';
    row.insertAdjacentElement('afterend', ed);
    var input = $('.pv-edit-value', ed);
    input.value = f.value;
    input.focus();
    input.select();
  }

  function openStageEditor() {
    var st = STAGES[currentStage];
    if (!st.fields.length) { return; }
    closeEditors();
    var ed = document.createElement('div');
    ed.className = 'pv-inline-editor';
    ed.innerHTML =
      '<div class="pv-edit-grid">' +
        '<div><label>变化项（参数）</label><select class="form-control form-control-sm pv-edit-field">' +
          st.fields.map(function (f, i) { return '<option value="' + i + '">' + f.label + '</option>'; }).join('') +
        '</select></div>' +
        '<div><label>本次实际值</label><input class="form-control form-control-sm pv-edit-value"></div>' +
      '</div>' +
      '<div class="pv-edit-grid" style="margin-top:10px">' +
        '<div><label>原因（可选）</label><input class="form-control form-control-sm pv-edit-reason" placeholder="例如：本次样本浓度低"></div>' +
        '<div><label>作用范围</label><select class="form-control form-control-sm pv-edit-scope">' +
          '<option>全部泳道</option><option>指定样本／泳道</option><option>整张膜</option>' +
        '</select></div>' +
      '</div>' +
      '<div class="bench-form-actions">' +
        '<button type="button" class="btn btn-primary btn-sm" data-pv-save-stage-change>保存记录</button>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" data-pv-cancel>取消</button>' +
        '<span class="smallgray">保存后追加一条变化记录；已单独设定的泳道不受影响。</span>' +
      '</div>';
    stageHost.appendChild(ed);
    $('.pv-edit-value', ed).focus();
  }

  if (stagePills) {
    stagePills.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-stage]');
      if (!btn) { return; }
      currentStage = parseInt(btn.getAttribute('data-stage'), 10);
      closeEditors();
      renderStagePills();
      renderFields();
    });
  }

  if (stageHost) {
    stageHost.addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      if (!btn) { return; }
      var editor = btn.closest('.pv-inline-editor');
      var row = editor ? editor.previousElementSibling : btn.closest('.pv-param-row');
      var st = STAGES[currentStage];

      if (btn.hasAttribute('data-pv-confirm') && row) {
        var fi = parseInt(row.getAttribute('data-idx'), 10);
        var ff = st.fields[fi];
        if (!ff) { return; }
        ff.state = 'confirmed';
        renderFields();
        tlPrepend('is-confirm', 'fas fa-check', '已确认按方案：' + ff.label + ' · ' + ff.value,
          '今天 ' + nowHM() + ' · 演示学生 A', '');
        flashSaved('记录已保存到服务器');
        return;
      }
      if (btn.hasAttribute('data-pv-change') && row) {
        var ci = parseInt(row.getAttribute('data-idx'), 10);
        openRowEditor(row, st.fields[ci]);
        return;
      }
      if (btn.hasAttribute('data-pv-save-change') && editor && editor.previousElementSibling) {
        var rr = editor.previousElementSibling;
        var idx = parseInt(rr.getAttribute('data-idx'), 10);
        var f = st.fields[idx];
        if (!f) { return; }
        var oldVal = f.value;
        var newVal = ($('.pv-edit-value', editor).value || '').trim() || oldVal;
        var reason = ($('.pv-edit-reason', editor).value || '').trim();
        f.value = newVal;
        f.state = 'overridden';
        f.reason = reason;
        renderFields();
        tlPrepend('is-change', 'fas fa-pen', '参数变化：' + f.label + ' ' + oldVal + ' → ' + newVal,
          '今天 ' + nowHM() + ' · 演示学生 A', reason);
        flashSaved('记录已保存到服务器');
        return;
      }
      if (btn.hasAttribute('data-pv-save-stage-change') && editor) {
        var sel = parseInt($('.pv-edit-field', editor).value, 10);
        var g = st.fields[sel];
        if (!g) { return; }
        var oldV = g.value;
        var newV = ($('.pv-edit-value', editor).value || '').trim() || oldV;
        var rsn = ($('.pv-edit-reason', editor).value || '').trim();
        var scope = $('.pv-edit-scope', editor).value;
        g.value = newV;
        g.state = 'overridden';
        g.reason = rsn;
        g.scope = scope !== '全部泳道' ? scope : '';
        renderFields();
        tlPrepend('is-change', 'fas fa-pen', '参数变化：' + g.label + ' ' + oldV + ' → ' + newV + '（' + scope + '）',
          '今天 ' + nowHM() + ' · 演示学生 A', rsn);
        flashSaved('记录已保存到服务器');
        return;
      }
      if (btn.hasAttribute('data-pv-cancel')) {
        closeEditors();
      }
    });
  }

  var addChangeBtn = $('#pv-add-change');
  if (addChangeBtn) {
    addChangeBtn.addEventListener('click', function () { openStageEditor(); });
  }

  /* 备注 / 异常 */
  var composer = null;
  function closeComposer() {
    if (composer && composer.parentElement) { composer.parentElement.removeChild(composer); }
    composer = null;
  }
  $$('[data-pv-compose]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var kind = btn.getAttribute('data-pv-compose');
      if (composer && composer.getAttribute('data-kind') === kind) { closeComposer(); return; }
      closeComposer();
      closeEditors();
      composer = document.createElement('div');
      composer.className = 'pv-inline-editor';
      composer.setAttribute('data-kind', kind);
      composer.innerHTML =
        '<label>' + (kind === 'anomaly' ? '异常内容（一句话说明异常即可）' : '备注（一句话）') + '</label>' +
        '<textarea class="form-control form-control-sm" rows="2" placeholder="' +
          (kind === 'anomaly' ? '例如：显影背景偏高，待观察' : '例如：条带位置正常，已拍照留档') + '"></textarea>' +
        '<div class="bench-form-actions">' +
          '<button type="button" class="btn btn-primary btn-sm" data-pv-save-compose>保存</button>' +
          '<button type="button" class="btn btn-outline-secondary btn-sm" data-pv-cancel-compose>取消</button>' +
          '<span class="smallgray">输入停顿约 2 秒自动保存草稿</span>' +
        '</div>';
      btn.closest('.bench-form-actions').insertAdjacentElement('afterend', composer);
      var ta = composer.querySelector('textarea');
      ta.focus();
      var t = null;
      ta.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
          setSave('<i class="far fa-cloud"></i><span>草稿已保存到服务器 · ' + nowHM() + '</span>', '');
        }, 1800);
      });
    });
  });
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button');
    if (!btn) { return; }
    if (btn.hasAttribute('data-pv-save-compose') && composer) {
      var kind = composer.getAttribute('data-kind');
      var text = (composer.querySelector('textarea').value || '').trim() || (kind === 'anomaly' ? '显影背景偏高，待观察' : '条带位置正常，已拍照留档');
      if (kind === 'anomaly') {
        tlPrepend('is-retro', 'fas fa-triangle-exclamation', '异常：' + text, '今天 ' + nowHM() + ' · 演示学生 A', '处理：待观察');
      } else {
        tlPrepend('', 'fas fa-comment', '备注：' + text, '今天 ' + nowHM() + ' · 演示学生 A', '');
      }
      closeComposer();
      flashSaved('记录已保存到服务器');
    } else if (btn.hasAttribute('data-pv-cancel-compose')) {
      closeComposer();
    }
  });

  /* 拍照 / 拖入文件 */
  var dropzone = $('#pv-dropzone');
  var uploadStrip = $('#pv-upload-strip');
  function addFileCard(name) {
    if (!uploadStrip || !dropzone) { return; }
    var card = document.createElement('div');
    card.className = 'pv-file-card';
    card.innerHTML =
      '<img src="assets/img/photo-bench.svg" alt="现场照片">' +
      '<div class="pv-file-meta"><strong>' + name + '</strong>现场照片 · 刚刚 · 已保存到服务器</div>';
    uploadStrip.insertBefore(card, dropzone);
    tlPrepend('is-file', 'fas fa-camera', '照片：已归入本实验', '今天 ' + nowHM() + ' · 演示学生 A · ' + name, '');
    flashSaved('照片已保存到服务器');
  }
  $$('[data-pv-photo]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      addFileCard('IMG_' + Math.floor(2000 + Math.random() * 900) + '.jpg');
    });
  });
  if (dropzone) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.remove('over'); });
    });
    dropzone.addEventListener('drop', function (e) {
      var name = (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) ? e.dataTransfer.files[0].name : '拖入的照片.jpg';
      addFileCard(name);
    });
    dropzone.addEventListener('click', function () {
      addFileCard('IMG_' + Math.floor(2000 + Math.random() * 900) + '.jpg');
    });
  }

  renderStagePills();
  renderFields();
})();
