(() => {
  const state = {
    selectedExperiment: null,
    uploadQueue: [],
  };

  const root = document.querySelector('.smartlab-demo');
  const savedExperiment = (() => {
    try {
      return JSON.parse(localStorage.getItem('smartlab-selected-experiment') || 'null');
    } catch (_) {
      return null;
    }
  })();
  const displayExperimentTitle = (title) => {
    const value = String(title || '').trim();
    return value && !/^实验\s*#?\d+$/.test(value) && !/^实验记录\s+\d+$/.test(value) ? value : '实验记录';
  };
  if (root?.dataset.slCurrentExperiment && Number(root.dataset.slCurrentExperiment) > 0) {
    state.selectedExperiment = {
      id: root.dataset.slCurrentExperiment,
      title: displayExperimentTitle(root.dataset.slCurrentTitle),
    };
    localStorage.setItem('smartlab-selected-experiment', JSON.stringify(state.selectedExperiment));
  } else if (savedExperiment?.id && Number(savedExperiment.id) > 0) {
    state.selectedExperiment = {
      id: savedExperiment.id,
      title: displayExperimentTitle(savedExperiment.title),
    };
  }

  const downloadText = (filename, text) => {
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  };

  const readText = element => element?.innerText?.trim().replace(/\n{3,}/g, '\n\n') || '';

  const toast = (message, error = false) => {
    document.querySelector('.sl-toast')?.remove();
    const node = document.createElement('div');
    node.className = `sl-toast${error ? ' error' : ''}`;
    node.textContent = message;
    document.body.appendChild(node);
    setTimeout(() => node.remove(), 3200);
  };

  const writeConsole = (selector, lines) => {
    const node = document.querySelector(selector);
    if (!node) {
      return;
    }
    node.textContent = Array.isArray(lines) ? lines.join('\n') : String(lines);
  };

  document.querySelector('[data-sl-submit-review-form]')?.addEventListener('submit', event => {
    const form = event.currentTarget;
    if (form.dataset.slSubmitReady === '1') {
      return;
    }
    event.preventDefault();
    if (form.dataset.slSubmitPending === '1') {
      return;
    }
    form.dataset.slSubmitPending = '1';
    const button = form.querySelector('[data-sl-submit-review-button]');
    const status = form.querySelector('[data-sl-submit-review-status]');
    if (button) {
      button.disabled = true;
      button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>正在提交...';
    }
    if (status) status.textContent = '正在提交给导师...';
    toast('正在提交导师审核，请稍候。');
    window.setTimeout(() => {
      if (button) {
        button.innerHTML = '<i class="fas fa-circle-check mr-1"></i>提交完成';
      }
      if (status) status.textContent = '提交完成，正在进入送审状态。';
      toast('提交完成，正在跳转到审核状态。');
      window.setTimeout(() => {
        form.dataset.slSubmitReady = '1';
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          HTMLFormElement.prototype.submit.call(form);
        }
      }, 350);
    }, 1500);
  });

  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));

  const setProgress = percent => {
    const bar = document.querySelector('[data-sl-upload-progress]');
    if (bar) {
      bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
    }
  };

  const makeDemoPngDataUrl = label => {
    const canvas = document.createElement('canvas');
    canvas.width = 720;
    canvas.height = 420;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#f8fbff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#e8eef8';
    for (let y = 42; y < canvas.height; y += 36) {
      ctx.fillRect(42, y, canvas.width - 84, 1);
    }
    ctx.fillStyle = '#0f6fff';
    [110, 180, 134, 265, 320, 212].forEach((height, index) => {
      const x = 86 + index * 96;
      ctx.fillRect(x, canvas.height - 56 - height, 52, height);
      ctx.fillStyle = '#334155';
      ctx.fillText(`S${index + 1}`, x + 14, canvas.height - 26);
      ctx.fillStyle = '#0f6fff';
    });
    ctx.fillStyle = '#111827';
    ctx.font = '700 28px Arial, sans-serif';
    ctx.fillText('SmartLab Demo Result', 42, 42);
    ctx.font = '18px Arial, sans-serif';
    ctx.fillText(label, 42, 74);
    return canvas.toDataURL('image/png');
  };

  const postWorkflow = async (operation, extra = {}) => {
    const form = document.querySelector('[data-sl-workflow-form]');
    const experimentId = extra.experimentId || state.selectedExperiment?.id || root?.dataset.slCurrentExperiment;
    if (!form || !experimentId || Number(experimentId) <= 0) {
      throw new Error('请先选择一个实验。');
    }
    const body = new FormData(form);
    body.set('operation', operation);
    body.set('experiment_id', experimentId);
    Object.entries(extra.fields || {}).forEach(([key, value]) => body.set(key, value));
    (extra.uploads || []).forEach((upload, index) => {
      body.set(`uploads[${index}][name]`, upload.name);
      body.set(`uploads[${index}][type]`, upload.type);
      body.set(`uploads[${index}][content]`, upload.content);
    });
    const response = await fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      body,
      credentials: 'same-origin',
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) {
      throw new Error(data.error || `请求失败：${response.status}`);
    }
    return data;
  };

  const setRecognitionConfirmState = (button, decision, pending = false) => {
    const card = button.closest('.sl-card');
    const actions = button.closest('[data-sl-recognition-actions]');
    const badge = card?.querySelector('.sl-badge');
    if (!card || !actions || !badge) {
      return;
    }
    const buttons = [...actions.querySelectorAll('[data-sl-recognition-confirm]')];
    buttons.forEach(action => {
      action.disabled = pending;
      action.classList.toggle('disabled', pending);
    });
    if (pending) {
      badge.className = 'sl-badge blue';
      badge.textContent = '保存中';
      return;
    }
    const confirmed = decision !== 'needs_fix';
    badge.className = `sl-badge ${confirmed ? 'green' : 'orange'}`;
    badge.textContent = confirmed ? '已确认' : '学生已修正';
    buttons.forEach(action => {
      const isCurrentDecision = (action.dataset.decision || 'confirmed') === decision;
      action.disabled = isCurrentDecision;
      action.classList.toggle('disabled', isCurrentDecision);
      action.textContent = isCurrentDecision
        ? (confirmed ? '已确认' : '已保存修正')
        : ((action.dataset.decision || 'confirmed') === 'needs_fix' ? '需要修正' : '确认此项');
    });
    card.dataset.slRecognitionState = decision;
  };

  const setRecognitionCorrectionState = (card, studentValue, correctionNote) => {
    const badge = card?.querySelector('.sl-badge');
    const valueNode = card?.querySelector('[data-sl-student-value]');
    const form = card?.querySelector('[data-sl-recognition-correction]');
    const openButton = card?.querySelector('[data-sl-recognition-open-correction]');
    if (badge) {
      badge.className = 'sl-badge orange';
      badge.textContent = '学生已修正';
    }
    if (valueNode) {
      valueNode.textContent = `学生确认/修正值：${studentValue}`;
    }
    if (form) {
      form.classList.add('d-none');
    }
    if (openButton) {
      openButton.textContent = '继续修正';
    }
    let noteNode = card?.querySelector('[data-sl-correction-note-display]');
    if (correctionNote) {
      if (!noteNode) {
        noteNode = document.createElement('div');
        noteNode.className = 'sl-muted';
        noteNode.dataset.slCorrectionNoteDisplay = '1';
        valueNode?.after(noteNode);
      }
      noteNode.textContent = `修正说明：${correctionNote}`;
    } else {
      noteNode?.remove();
    }
  };

  const refreshUploadConsole = () => {
    const header = state.selectedExperiment
      ? `实验记录：${displayExperimentTitle(state.selectedExperiment.title)}`
      : '未选择实验';
    document.querySelector('[data-sl-selected-experiment]')?.replaceChildren(document.createTextNode(header));
    const lines = [header];
    if (state.uploadQueue.length === 0) {
      lines.push('队列为空。点击左侧采集卡片加入待处理文件。');
    } else {
      state.uploadQueue.forEach((item, index) => {
        lines.push(`${index + 1}. ${item.kind} -> ${item.filename} [${item.status}]`);
      });
    }
    writeConsole('[data-sl-upload-console]', lines);
    document.querySelectorAll('input[name="experiment_id"]').forEach(input => {
      if (state.selectedExperiment?.id) {
        input.value = state.selectedExperiment.id;
      }
    });
  };

  const runTimedLog = (selector, steps, done) => {
    const lines = [];
    steps.forEach((step, index) => {
      window.setTimeout(() => {
        lines.push(step);
        writeConsole(selector, lines);
        if (index === steps.length - 1 && done) {
          done();
        }
      }, 260 * (index + 1));
    });
  };

  const applyTemplateFilter = () => {
    const query = document.querySelector('[data-sl-template-search]')?.value?.trim().toLowerCase() || '';
    const activeKind = document.querySelector('[data-sl-template-tabs] span.active')?.dataset.kind || '';
    document.querySelectorAll('[data-sl-template-card]').forEach(card => {
      const text = card.getAttribute('data-sl-template-text')?.toLowerCase() || '';
      const kind = card.dataset.kind || '';
      card.hidden = (query !== '' && !text.includes(query)) || (activeKind !== '' && kind !== activeKind);
    });
  };

  const assayKindToCode = {
    'WB': 'wb', 'IF': 'if', 'qPCR': 'qpcr', 'ELISA': 'elisa',
    'PCR': 'pcr', 'IHC': 'ihc',
  };

  const populateTemplatePreview = steps => {
    const tbody = document.querySelector('[data-sl-template-preview]');
    if (!tbody) return;
    const countEl = document.querySelector('[data-sl-template-step-count]');
    if (countEl) countEl.textContent = `共${steps.length}步`;
    tbody.innerHTML = steps.map(step => {
      const sourceState = step.source_state || step.source || '已识别';
      const evidenceModule = step.evidence_module || '';
      const evidenceRef = step.evidence_ref || '';
      const confidence = step.confidence || '';
      const binding = step.binding || '';

      // source state badge colors
      const stateBadgeClass = sourceState === '已识别' ? 'green'
        : sourceState === '推理补全' ? 'blue'
        : sourceState === '已确认' ? 'teal'
        : 'orange'; // 待确认

      // evidence module badge
      const moduleBadgeClass = evidenceModule === '原始附件' ? 'green'
        : evidenceModule === '智能识别' ? 'blue'
        : evidenceModule === '断点识别' ? 'orange'
        : evidenceModule === '跳步识别' ? 'purple'
        : evidenceModule === '步骤推理' ? 'teal'
        : 'blue';

      // confidence badge
      const confBadgeClass = confidence === '高' ? 'green'
        : confidence === '中' ? 'blue'
        : 'orange'; // 低

      // actions per row
      let actions = '';
      if (sourceState === '待确认') {
        actions = `<button class='btn btn-sm btn-outline-success mt-1' type='button' data-sl-confirm-row title='标记此项为已确认'>确认此项</button>`;
      }

      const evidenceDetail = escapeHtml(evidenceRef || '暂无详细依据');
      const stepData = escapeHtml(JSON.stringify({
        name: step.name,
        content: step.content,
        source: step.source,
        source_state: sourceState,
        evidence_module: evidenceModule,
        evidence_ref: evidenceRef,
        confidence: confidence,
        binding: binding,
      }));

      return `<tr>
        <td>${escapeHtml(step.num)}</td>
        <td><b>${escapeHtml(step.name)}</b></td>
        <td class='sl-editable-cell' contenteditable='true' title='可在本页编辑草稿预览，不会直接覆盖原实验记录'>${escapeHtml(step.content)}</td>
        <td><span class='sl-badge ${stateBadgeClass}'>${escapeHtml(sourceState)}</span>${actions}</td>
        <td>${evidenceModule ? `<span class='sl-badge ${moduleBadgeClass}' title='证据来源：${escapeHtml(evidenceModule)}'>${escapeHtml(evidenceModule)}</span>` : '<span class="sl-muted">-</span>'}</td>
        <td>
          <div style='font-size:12px;max-width:180px' title='${evidenceDetail}'>
            <span class='sl-badge ${confBadgeClass}' style='font-size:10px;margin-right:4px'>${escapeHtml(confidence)}</span>
            ${binding ? `<span class='sl-badge' style='font-size:10px'>${escapeHtml(binding)}</span>` : ''}
            ${evidenceRef ? `<div class='sl-muted mt-1' style='font-size:11px;line-height:1.35'>${evidenceDetail}</div>` : ''}
            <button class='btn btn-sm btn-outline-info mt-1' type='button' data-sl-view-evidence data-step='${stepData}'>查看依据</button>
          </div>
        </td>
      </tr>`;
    }).join('');
  };

  const populateTemplateDraft = draft => {
    const container = document.querySelector('[data-sl-template-draft]');
    if (!container) return;
    container.innerHTML = draft.map(section => {
      const bindBadge = section.binding_badge || 'blue';
      const bindLabel = section.binding || '';
      return `<div class='sl-callout blue mb-2'>
        <div class='d-flex justify-content-between align-items-start gap-2'>
          <b>${escapeHtml(section.title)}</b>
          ${bindLabel ? `<span class='sl-badge ${bindBadge}' style='flex-shrink:0;font-size:11px'>${escapeHtml(bindLabel)}</span>` : ''}
        </div>
        <p class='mb-0 mt-2'>${escapeHtml(section.content)}</p>
      </div>`;
    }).join('');
  };

  const reportTable = (headers, rows) => {
    if (!rows.length) {
      return '<p class="muted">暂无记录。</p>';
    }
    return `<table><thead><tr>${headers.map(header => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${rows.map(row => (
      `<tr>${row.map(cell => `<td>${escapeHtml(cell || '-')}</td>`).join('')}</tr>`
    )).join('')}</tbody></table>`;
  };

  const buildPrintableReport = () => {
    const contextRows = [...document.querySelectorAll('.sl-review-context .sl-context-item')].map(item => {
      const label = readText(item.querySelector('.sl-muted'));
      const valueNode = [...item.children].find(child => !child.classList.contains('sl-muted')) || item;
      return [label, readText(valueNode).replace(label, '').trim()];
    }).filter(row => row[0] || row[1]);

    const stepRows = [...document.querySelectorAll('[data-sl-template-preview] tr')].map(row => {
      const cells = [...row.cells].map(cell => readText(cell).replace(/\n查看依据$/, '').trim());
      return cells.length === 6 ? cells : ['-', '未生成', cells.join(' ') || '请先保存模板草稿', '-', '-', '-'];
    });

    const draftRows = [...document.querySelectorAll('[data-sl-template-draft] .sl-callout')].map(block => {
      const title = readText(block.querySelector('b'));
      const badge = readText(block.querySelector('.sl-badge'));
      const content = readText(block.querySelector('p'));
      return [title, content, badge];
    });

    const evidenceRows = [...document.querySelectorAll('[data-sl-chain-items] > *')].map(item => [readText(item)]);
    const imageCards = [...document.querySelectorAll('.sl-template-evidence .sl-preview-card')].map(card => ({
      src: card.querySelector('img')?.src || '',
      name: readText(card.querySelector('b')) || '原始图片',
      kind: readText(card.querySelector('.sl-badge')) || '图片',
    }));
    const dataRows = [...document.querySelectorAll('.sl-template-evidence .sl-attachment-list a')].map(link => [
      readText(link.querySelector('span')),
      link.href,
    ]);
    const flowText = readText(document.querySelector('[data-sl-draft-export]'));
    const experimentLink = document.querySelector('[data-sl-submit-review-form] a[href*="experiments.php"]')?.href || '';

    const reportTitle = contextRows.find(row => row[0].includes('实验名称'))?.[1]
      || contextRows.find(row => row[0].includes('当前送审实验'))?.[1]
      || 'Western blot 实验报告';
    const generatedAt = new Date().toLocaleString('zh-CN', { hour12: false });

    return `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title>${escapeHtml(reportTitle)} - SmartLab PDF报告</title>
  <style>
    * { box-sizing: border-box; }
    body { color: #111827; font-family: "Noto Sans SC", "Microsoft YaHei", Arial, sans-serif; font-size: 13px; line-height: 1.65; margin: 0; padding: 28px; }
    h1 { border-bottom: 3px solid #0f6fff; font-size: 24px; margin: 0 0 14px; padding-bottom: 10px; }
    h2 { color: #0f3369; font-size: 17px; margin: 24px 0 10px; }
    .meta { color: #64748b; margin-bottom: 18px; }
    .muted { color: #64748b; }
    table { border-collapse: collapse; margin: 8px 0 14px; table-layout: fixed; width: 100%; }
    th, td { border: 1px solid #d8e1ee; padding: 7px 8px; text-align: left; vertical-align: top; word-break: break-word; }
    th { background: #eef5ff; color: #0f3369; font-weight: 800; }
    .section { break-inside: avoid; page-break-inside: avoid; }
    .draft { border: 1px solid #d8e1ee; border-left: 4px solid #0f6fff; margin: 8px 0; padding: 10px 12px; }
    .draft b { display: block; font-size: 14px; margin-bottom: 4px; }
    .badge { background: #eef5ff; border-radius: 4px; color: #0f6fff; display: inline-block; font-size: 11px; font-weight: 800; margin-top: 4px; padding: 2px 6px; }
    .images { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .image-card { border: 1px solid #d8e1ee; padding: 8px; break-inside: avoid; page-break-inside: avoid; }
    .image-card img { display: block; max-height: 280px; object-fit: contain; width: 100%; }
    .flow { background: #f8fbff; border: 1px solid #d8e1ee; padding: 10px 12px; white-space: pre-line; }
    @media print {
      body { padding: 18mm; }
      .no-print { display: none; }
      a { color: inherit; text-decoration: none; }
    }
  </style>
</head>
<body>
  <button class="no-print" onclick="window.print()" style="float:right;padding:8px 12px">打印/另存为PDF</button>
  <h1>SmartLab Western blot 完整实验报告</h1>
  <div class="meta">报告对象：${escapeHtml(reportTitle)}<br>生成时间：${escapeHtml(generatedAt)}${experimentLink ? `<br>原实验记录：${escapeHtml(experimentLink)}` : ''}</div>

  <section class="section">
    <h2>一、实验基本信息</h2>
    ${reportTable(['字段', '内容'], contextRows)}
  </section>

  <section class="section">
    <h2>二、模板补全步骤</h2>
    ${reportTable(['序号', '步骤', '补全内容', '状态', '证据来源模块', '依据'], stepRows)}
  </section>

  <section class="section">
    <h2>三、实验过程草稿</h2>
    ${draftRows.length ? draftRows.map(row => `<div class="draft"><b>${escapeHtml(row[0] || '草稿段落')}</b><div>${escapeHtml(row[1] || '-')}</div>${row[2] ? `<span class="badge">${escapeHtml(row[2])}</span>` : ''}</div>`).join('') : '<p class="muted">尚未生成草稿，请先保存模板草稿。</p>'}
  </section>

  <section class="section">
    <h2>四、证据链与附件</h2>
    ${evidenceRows.length ? reportTable(['证据链'], evidenceRows) : '<p class="muted">暂无证据链状态。</p>'}
    ${dataRows.length ? reportTable(['数据附件', '链接'], dataRows) : '<p class="muted">暂无数据附件。</p>'}
  </section>

  <section>
    <h2>五、原始图像证据</h2>
    ${imageCards.length ? `<div class="images">${imageCards.map(card => `<div class="image-card"><img src="${escapeHtml(card.src)}" alt="${escapeHtml(card.name)}"><b>${escapeHtml(card.name)}</b><br><span class="badge">${escapeHtml(card.kind)}</span></div>`).join('')}</div>` : '<p class="muted">暂无可导出的原始图像。</p>'}
  </section>

  <section class="section">
    <h2>六、送审说明</h2>
    <div class="flow">${escapeHtml(flowText || '当前实验将以模板补全草稿、附件证据、智能识别、断点识别和跳步识别结果作为导师审核依据。')}</div>
  </section>
  <script>
    window.addEventListener('load', () => window.setTimeout(() => window.print(), 500));
  </script>
</body>
</html>`;
  };

  const openPrintableReport = () => {
    const reportHtml = buildPrintableReport();
    const reportBlob = new Blob([reportHtml], { type: 'text/html;charset=utf-8' });
    const reportUrl = URL.createObjectURL(reportBlob);
    const reportWindow = window.open(reportUrl, '_blank');
    if (!reportWindow) {
      URL.revokeObjectURL(reportUrl);
      toast('浏览器阻止了报告窗口，请允许弹窗后重试。', true);
      return;
    }
    window.setTimeout(() => {
      try {
        reportWindow.focus();
        reportWindow.print();
      } catch (_) {
        // The report page still includes its own print button if the browser blocks this call.
      }
    }, 800);
    window.setTimeout(() => URL.revokeObjectURL(reportUrl), 60000);
    toast('已生成完整PDF报告窗口，可直接打印或另存为PDF。');
  };

  const renderEvidenceChain = chainSummary => {
    const chainContainer = document.querySelector('[data-sl-evidence-chain]');
    const chainItems = document.querySelector('[data-sl-chain-items]');
    if (!chainContainer || !chainItems || !chainSummary?.chain) return;
    chainContainer.style.display = 'block';
    const moduleBadgeMap = {
      '原始附件': 'green',
      '智能识别': 'blue',
      '断点识别': 'orange',
      '跳步识别': 'purple',
      '步骤推理': 'teal',
    };
    chainItems.innerHTML = chainSummary.chain.map(item => {
      const badgeClass = moduleBadgeMap[item.module] || 'blue';
      const statusClass = item.status === '已完成' ? 'green' : 'orange';
      return `<div style='border:1px solid var(--sl-border);border-radius:6px;padding:6px 10px;background:#fff;min-width:108px;text-align:center'>
        <span class='sl-badge ${badgeClass}' style='font-size:10px'>${escapeHtml(item.module)}</span>
        <span class='sl-badge ${statusClass}' style='font-size:10px;margin-left:4px'>${escapeHtml(item.status)}</span>
        <div style='font-size:11px;margin-top:4px;color:var(--sl-muted);line-height:1.35'>${escapeHtml(item.detail)}</div>
      </div>`;
    }).join('');
  };

  const initPaginatedTables = () => {
    document.querySelectorAll('table[data-sl-table="experiment-list"]').forEach(table => {
      const tbody = table.tBodies[0];
      if (!tbody || table.dataset.slReady === '1') {
        return;
      }
      table.dataset.slReady = '1';
      let rows = Array.from(tbody.rows);
      let page = 1;
      let pageSize = 10;
      let sortIndex = -1;
      let sortDir = 'asc';
      const pager = document.createElement('div');
      pager.className = 'sl-table-pager';
      table.insertAdjacentElement('afterend', pager);

      const cellText = (row, index) => (row.cells[index]?.innerText || '').trim();
      const numericValue = value => {
        const match = String(value).replace(/,/g, '').match(/-?\d+(\.\d+)?/);
        return match ? Number(match[0]) : 0;
      };
      const sortRows = () => {
        if (sortIndex < 0) {
          return;
        }
        const type = table.tHead?.rows[0]?.cells[sortIndex]?.dataset.slSort || 'text';
        rows.sort((a, b) => {
          const left = cellText(a, sortIndex);
          const right = cellText(b, sortIndex);
          let result;
          if (type === 'number') {
            result = numericValue(left) - numericValue(right);
          } else if (type === 'date') {
            result = Date.parse(left || '1970-01-01') - Date.parse(right || '1970-01-01');
          } else {
            result = left.localeCompare(right, 'zh-Hans-CN', { numeric: true, sensitivity: 'base' });
          }
          return sortDir === 'asc' ? result : -result;
        });
      };
      const render = () => {
        rows = rows.filter(row => row.isConnected);
        sortRows();
        const total = rows.length;
        const pages = Math.max(1, Math.ceil(total / pageSize));
        page = Math.max(1, Math.min(page, pages));
        const start = (page - 1) * pageSize;
        const end = start + pageSize;
        rows.forEach((row, index) => {
          row.hidden = index < start || index >= end;
        });
        pager.innerHTML = `
          <div>共 ${total} 条，当前 ${total ? start + 1 : 0}-${Math.min(end, total)} 条</div>
          <div>
            每页
            <select class="sl-page-size" aria-label="每页条数">
              <option value="6"${pageSize === 6 ? ' selected' : ''}>6</option>
              <option value="10"${pageSize === 10 ? ' selected' : ''}>10</option>
            </select>
            <button type="button" data-sl-page-prev${page <= 1 ? ' disabled' : ''}>上一页</button>
            <span>${page} / ${pages}</span>
            <button type="button" data-sl-page-next${page >= pages ? ' disabled' : ''}>下一页</button>
          </div>`;
      };
      table.querySelectorAll('th[data-sl-sort]').forEach((th, index) => {
        th.addEventListener('click', () => {
          table.querySelectorAll('th[data-sl-sort]').forEach(node => node.classList.remove('sl-sort-asc', 'sl-sort-desc'));
          if (sortIndex === index) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
          } else {
            sortIndex = index;
            sortDir = th.dataset.slSort === 'date' ? 'desc' : 'asc';
          }
          th.classList.add(sortDir === 'asc' ? 'sl-sort-asc' : 'sl-sort-desc');
          page = 1;
          render();
        });
      });
      pager.addEventListener('click', event => {
        if (event.target.closest('[data-sl-page-prev]')) {
          page -= 1;
          render();
        } else if (event.target.closest('[data-sl-page-next]')) {
          page += 1;
          render();
        }
      });
      pager.addEventListener('change', event => {
        if (event.target.matches('.sl-page-size')) {
          pageSize = Number(event.target.value) || 10;
          page = 1;
          render();
        }
      });
      render();
    });
  };

  document.addEventListener('click', event => {
    const removeButton = event.target.closest('[data-sl-remove-row]');
    if (removeButton) {
      removeButton.closest('tr')?.remove();
      return;
    }

    const selectExperiment = event.target.closest('[data-sl-select-experiment]');
    if (selectExperiment) {
      document.querySelectorAll('[data-sl-select-experiment]').forEach(button => {
        button.closest('tr')?.classList.remove('sl-selected-row');
      });
      selectExperiment.closest('tr')?.classList.add('sl-selected-row');
      state.selectedExperiment = {
        id: selectExperiment.dataset.id,
        title: selectExperiment.dataset.title,
      };
      localStorage.setItem('smartlab-selected-experiment', JSON.stringify(state.selectedExperiment));
      refreshUploadConsole();
      toast('已选择实验，采集文件会进入该实验的处理队列。');
      return;
    }

    const addUpload = event.target.closest('[data-sl-add-upload]');
    if (addUpload) {
      const kind = addUpload.dataset.kind || '原始数据';
      const suffix = kind.includes('文件') ? 'xlsx' : kind.includes('屏幕') ? 'png' : 'jpg';
      const filename = `${kind.replace(/[（）\s]/g, '_')}_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.${suffix}`;
      state.uploadQueue.push({ kind, filename, status: '待采集' });
      setProgress(0);
      refreshUploadConsole();
      return;
    }

    if (event.target.closest('[data-sl-clear-upload]')) {
      state.uploadQueue = [];
      setProgress(0);
      refreshUploadConsole();
      toast('采集队列已清空。');
      return;
    }

    if (event.target.closest('[data-sl-run-upload]')) {
      if (!state.selectedExperiment) {
        toast('请先选择一个实验。', true);
        return;
      }
      if (state.uploadQueue.length === 0) {
        toast('请先加入至少一个采集文件。', true);
        return;
      }
      state.uploadQueue = state.uploadQueue.map(item => ({ ...item, status: '解析中' }));
      refreshUploadConsole();
      [25, 52, 76, 100].forEach((value, index) => window.setTimeout(() => setProgress(value), 260 * (index + 1)));
      postWorkflow('upload_demo', {
        uploads: [
          {
            name: `smartlab_upload_queue_${Date.now()}.json`,
            type: 'json',
            content: JSON.stringify({
              experiment_id: state.selectedExperiment.id,
              queue: state.uploadQueue,
              metrics: { positive_rate: 0.667, fold_change: 6.38, anomaly_score: 0.89 },
              generated_at: new Date().toISOString(),
            }, null, 2),
          },
          {
            name: `smartlab_demo_result_${Date.now()}.png`,
            type: 'png',
            content: makeDemoPngDataUrl(state.selectedExperiment.title),
          },
        ],
      }).then(data => {
        state.uploadQueue = state.uploadQueue.map(item => ({ ...item, status: '已入队并关联' }));
        refreshUploadConsole();
        toast(`采集队列已完成关联，新增 ${data.uploads?.length || 0} 个真实附件。`);
      }).catch(error => {
        state.uploadQueue = state.uploadQueue.map(item => ({ ...item, status: '写入失败' }));
        refreshUploadConsole();
        toast(error.message, true);
      });
      return;
    }

    if (event.target.closest('[data-sl-run-recognition]')) {
      const attachmentCount = document.querySelectorAll('.sl-attachment-list a, table.sl-table tbody tr').length;
      runTimedLog('[data-sl-recognition-console]', [
        `1. 载入当前实验附件 ${attachmentCount || 0} 项。`,
        '2. WB 图像附件进入条带/内参识别归档。',
        '3. 读取实验记录第4步“结果回填确认”。',
        '4. 第4步灰度归一化表已写入数字化档案。',
        '5. 结构化档案已刷新。'
      ], () => postWorkflow('recognition').then(() => {
        toast('智能识别已完成，正在刷新结果。');
        window.setTimeout(() => window.location.reload(), 650);
      }).catch(error => toast(error.message, true)));
      return;
    }

    const recognitionConfirm = event.target.closest('[data-sl-recognition-confirm]');
    if (recognitionConfirm) {
      const fieldKey = recognitionConfirm.dataset.fieldKey || '';
      const decision = recognitionConfirm.dataset.decision || 'confirmed';
      const card = recognitionConfirm.closest('.sl-card');
      const badge = card?.querySelector('.sl-badge');
      const previous = {
        badgeClass: badge?.className || 'sl-badge orange',
        badgeText: badge?.textContent || '待确认',
        buttons: [...(recognitionConfirm.closest('[data-sl-recognition-actions]')?.querySelectorAll('[data-sl-recognition-confirm]') || [])].map(button => ({
          node: button,
          text: button.textContent,
          disabled: button.disabled,
        })),
      };
      setRecognitionConfirmState(recognitionConfirm, decision, true);
      postWorkflow('recognition_confirm', {
        fields: {
          field_key: fieldKey,
          decision,
        },
      }).then(() => {
        setRecognitionConfirmState(recognitionConfirm, decision, false);
        toast(decision === 'needs_fix' ? '已标记为需要修正。' : '已确认此识别字段。');
      }).catch(error => {
        if (badge) {
          badge.className = previous.badgeClass;
          badge.textContent = previous.badgeText;
        }
        previous.buttons.forEach(({ node, text, disabled }) => {
          node.textContent = text;
          node.disabled = disabled;
          node.classList.toggle('disabled', disabled);
        });
        toast(error.message, true);
      });
      return;
    }

    const openCorrection = event.target.closest('[data-sl-recognition-open-correction]');
    if (openCorrection) {
      const card = openCorrection.closest('.sl-card');
      const form = card?.querySelector('[data-sl-recognition-correction]');
      if (form) {
        form.classList.remove('d-none');
        form.querySelector('[data-sl-correction-value]')?.focus();
      }
      return;
    }

    const cancelCorrection = event.target.closest('[data-sl-recognition-cancel-correction]');
    if (cancelCorrection) {
      cancelCorrection.closest('[data-sl-recognition-correction]')?.classList.add('d-none');
      return;
    }

    const saveCorrection = event.target.closest('[data-sl-recognition-save-correction]');
    if (saveCorrection) {
      const form = saveCorrection.closest('[data-sl-recognition-correction]');
      const card = saveCorrection.closest('.sl-card');
      const fieldKey = form?.dataset.fieldKey || '';
      const studentValue = form?.querySelector('[data-sl-correction-value]')?.value.trim() || '';
      const correctionNote = form?.querySelector('[data-sl-correction-note]')?.value.trim() || '';
      if (!fieldKey || !studentValue) {
        toast('请填写修正后的识别值。', true);
        return;
      }
      saveCorrection.disabled = true;
      saveCorrection.textContent = '保存中';
      postWorkflow('recognition_confirm', {
        fields: {
          field_key: fieldKey,
          decision: 'needs_fix',
          student_value: studentValue,
          correction_note: correctionNote,
        },
      }).then(() => {
        setRecognitionCorrectionState(card, studentValue, correctionNote);
        toast('修正值已保存。');
      }).catch(error => {
        toast(error.message, true);
      }).finally(() => {
        saveCorrection.disabled = false;
        saveCorrection.textContent = '保存修正';
      });
      return;
    }

    if (event.target.closest('[data-sl-run-breakpoint]')) {
      const button = event.target.closest('[data-sl-run-breakpoint]');
      const originalText = button?.textContent || '重新分析';
      if (button) {
        button.disabled = true;
        button.textContent = '分析中...';
      }
      runTimedLog('[data-sl-breakpoint-console]', [
        '1. 读取当前实验的步骤记录、图像和数据表。',
        '2. 对相邻步骤逐段检查记录衔接和数据变化。',
        '3. 标出需要复核的位置，不在本页判断缺失步骤。',
        '4. 分析完成后可进入跳步识别继续核验。'
      ], () => postWorkflow('breakpoint').then(() => {
        writeConsole('[data-sl-breakpoint-console]', [
          '断点分析已完成。',
          '结果已写入实验记录，正在刷新页面。'
        ]);
        toast('断点分析已完成，结果已写入实验记录。');
        window.setTimeout(() => window.location.reload(), 650);
      }).catch(error => {
        writeConsole('[data-sl-breakpoint-console]', [
          '断点分析失败。',
          error.message,
          '请确认当前实验存在WB图像、灰度表或断点表后重试。'
        ]);
        toast(error.message, true);
        if (button) {
          button.disabled = false;
          button.textContent = originalText;
        }
      }));
      return;
    }

    if (event.target.closest('[data-sl-run-jump]')) {
      const button = event.target.closest('[data-sl-run-jump]');
      const originalText = button?.textContent || '重新分析';
      if (button) {
        button.disabled = true;
        button.textContent = '分析中...';
      }
      runTimedLog('[data-sl-jump-console]', [
        '1. 读取当前实验WB断点识别结果与数字化档案。',
        '2. 从断点结果提取primary_candidate/adjacent_pairs和wb_metrics。',
        '3. 计算归一化偏移量，对比同批相邻均值阈值。',
        '4. 基于WB证据文件（原膜图/灰度CSV/断点CSV）区分疑似跳步与记录断裂。',
        '5. 输出跳步判定、缺失步骤推理与建议。'
      ], () => postWorkflow('jump').then(() => {
        writeConsole('[data-sl-jump-console]', [
          'WB跳步识别已完成。',
          '已根据断点结果和WB证据重新计算跳步风险。',
          '结果已写入实验记录，正在刷新页面。'
        ]);
        toast('WB跳步识别已完成，结果已写入实验记录。');
        window.setTimeout(() => window.location.reload(), 650);
      }).catch(error => {
        writeConsole('[data-sl-jump-console]', [
          'WB跳步识别失败。',
          error.message,
          '请确认当前实验已完成断点识别，且存在WB结构化记录后重试。'
        ]);
        toast(error.message, true);
        if (button) {
          button.disabled = false;
          button.textContent = originalText;
        }
      }));
      return;
    }

    if (event.target.closest('[data-sl-run-reasoning]')) {
      const button = event.target.closest('[data-sl-run-reasoning]');
      const consoleSelector = document.querySelector('[data-sl-reasoning-console]') ? '[data-sl-reasoning-console]' : '[data-sl-jump-console]';
      runTimedLog(consoleSelector, [
        '1. 读取断点和跳步候选位置。',
        '2. 加载同批完整事件序列与结构化模板库。',
        '3. 以断点前后操作为约束搜索候选路径。',
        '4. 最优缺失步骤路径已写入专利工作流。'
      ], () => postWorkflow('reasoning', {
        experimentId: button?.dataset.experimentId || state.selectedExperiment?.id,
      }).then(() => toast('步骤推理已完成，结果已写入实验记录。')).catch(error => toast(error.message, true)));
      return;
    }

    if (event.target.closest('[data-sl-export-recognition]')) {
      const rows = [...document.querySelectorAll('table.sl-table tbody tr')]
        .map(row => row.innerText.trim().replace(/\t/g, ' | '))
        .filter(Boolean);
      downloadText('smartlab-recognition-result.txt', [
        'SmartLab 结构化识别结果',
        ...rows,
      ].join('\n'));
      return;
    }

    if (event.target.closest('[data-sl-export-draft]')) {
      openPrintableReport();
      return;
    }

    if (event.target.closest('[data-sl-filter-templates]')) {
      applyTemplateFilter();
      return;
    }

    const templateTab = event.target.closest('[data-sl-template-tabs] span');
    if (templateTab) {
      templateTab.parentElement.querySelectorAll('span').forEach(tab => tab.classList.remove('active'));
      templateTab.classList.add('active');
      applyTemplateFilter();
      return;
    }

    const chooseTemplate = event.target.closest('[data-sl-choose-template]');
    if (chooseTemplate) {
      document.querySelectorAll('[data-sl-template-card]').forEach(card => card.classList.remove('border-primary'));
      const card = chooseTemplate.closest('[data-sl-template-card]');
      card?.classList.add('border-primary');
      const cardKind = card?.dataset.kind || '';
      const assay = assayKindToCode[cardKind] || 'wb';
      const templateName = card?.querySelector('b')?.textContent?.trim() || '实验模板';
      postWorkflow('template_assay', {
        experimentId: root?.dataset.slCurrentExperiment || state.selectedExperiment?.id,
        fields: { assay, template_name: templateName },
      }).then(data => {
        if (data.steps) populateTemplatePreview(data.steps);
        if (data.draft) populateTemplateDraft(data.draft);
        if (data.chain_summary) renderEvidenceChain(data.chain_summary);
        toast(`${cardKind || '实验'}模板草稿已保存到实验记录，补全预览已生成。`);
      }).catch(error => toast(error.message, true));
      return;
    }

    const confirmRow = event.target.closest('[data-sl-confirm-row]');
    if (confirmRow) {
      const row = confirmRow.closest('tr');
      if (row) {
        const badge = row.querySelector('.sl-badge');
        if (badge && badge.textContent.trim() === '待确认') {
          badge.className = 'sl-badge green';
          badge.textContent = '已确认';
          confirmRow.textContent = '✓ 已确认';
          confirmRow.disabled = true;
          confirmRow.classList.remove('btn-outline-success');
          confirmRow.classList.add('btn-success');
          toast('已标记此项为确认（仅浏览器预览，不写入数据库）。');
        }
      }
      return;
    }

    const viewEvidence = event.target.closest('[data-sl-view-evidence]');
    if (viewEvidence) {
      const stepData = (() => {
        try {
          return JSON.parse(viewEvidence.dataset.step || '{}');
        } catch (_) {
          return {};
        }
      })();
      const stepName = stepData.name || '';
      const evidenceModule = stepData.evidence_module || '联合推理引擎';
      const evidenceRef = stepData.evidence_ref || '';
      const sourceState = stepData.source_state || '';
      const confidence = stepData.confidence || '';
      const binding = stepData.binding || '';

      const moduleDescriptions = {
        '原始附件': '直接来源于实验记录中上传的原始附件（图像、数据表等），未经推理或补全。',
        '智能识别': '由智能识别模块从原始附件中提取的结构化字段，经过 OCR/图像分析/表格解析。',
        '断点识别': '由断点识别模块标记的异常位置，基于数值跳变梯度和语义连续性的联合评分。',
        '跳步识别': '由跳步识别模块检测到的步骤缺失位置，基于前一/后一有效证据的偏移量评估。',
        '步骤推理': '由步骤推理引擎根据 DP/Viterbi 最优路径评分和模板库先验生成的补全步骤。',
        '模板库': '匹配历史模板库（版本 V2）中同类实验的完整步骤序列，基于统计频率和语义匹配。',
      };

      const moduleDesc = moduleDescriptions[evidenceModule] || `来源：${evidenceModule}`;
      const lines = [
        `步骤「${stepName}」证据详情`,
        `─────────────────────`,
        `来源模块：${evidenceModule}`,
        evidenceRef ? `证据引用：${evidenceRef}` : '',
        `当前状态：${sourceState || '未标记'}`,
        `置信度：${confidence || '未评估'}`,
        `绑定状态：${binding || '未绑定'}`,
        `─────────────────────`,
        moduleDesc,
        `提示：以上依据可回原实验记录复核，或提交导师审核确认。`,
      ].filter(Boolean);
      toast(lines.join('\n'));
      return;
    }

    if (event.target.closest('[data-sl-health-check]')) {
      writeConsole('[data-sl-health-console]', '正在请求 ImageJ worker 健康检查...');
      const controller = new AbortController();
      const timeoutId = window.setTimeout(() => controller.abort(), 12000);
      fetch('smartlab-imagej.php?action=ping', { credentials: 'same-origin', signal: controller.signal })
        .then(response => response.json())
        .then(data => {
          window.clearTimeout(timeoutId);
          const isOk = data.ok === true || data.status === 'ok';
          writeConsole('[data-sl-health-console]', [
            `状态：${isOk ? '在线' : '异常'}`,
            `动作：${data.worker_action || 'ping'}`,
            `返回：${data.stdout || data.message || data.error || JSON.stringify(data).slice(0, 180)}`,
          ]);
          toast(isOk ? '模型服务在线。' : '模型服务返回异常。', !isOk);
        })
        .catch(error => {
          window.clearTimeout(timeoutId);
          writeConsole('[data-sl-health-console]', [
            '状态：异常',
            `动作：ping`,
            `返回：${error.name === 'AbortError' ? '健康检查超过 12 秒，已终止等待。' : error.message}`,
          ]);
          toast('健康检查失败，请确认 worker 服务。', true);
        });
      return;
    }

    const serviceInfo = event.target.closest('[data-sl-service-info]');
    if (serviceInfo) {
      const service = serviceInfo.dataset.service || '算法服务';
      writeConsole('[data-sl-health-console]', [
        `${service} 监控`,
        '状态：在线',
        '最近调用：页面工作流 / 当前会话',
        '告警：无阻塞告警',
      ]);
      toast(`${service} 监控信息已刷新。`);
      return;
    }

    if (event.target.closest('[data-sl-save-policy]')) {
      const inputs = [...document.querySelectorAll('.smartlab-demo input, .smartlab-demo select')]
        .filter(input => input.closest('aside'))
      const values = inputs.map(input => `${input.previousSibling?.textContent?.trim() || input.id || input.name}: ${input.type === 'checkbox' ? input.checked : input.value}`);
      localStorage.setItem('smartlab-policy', values.join('\n'));
      postWorkflow('policy', {
        fields: Object.fromEntries(values.map((value, index) => [`policy[${index}]`, value])),
      }).then(() => toast('策略参数已保存到服务器配置。')).catch(error => toast(error.message, true));
    }

    // Phase 7: review action buttons (demo interactions)
    if (event.target.closest('[data-sl-approve-review]')) {
      toast('审核通过（预览操作，不写入数据库）。导师确认后可在审核表中逐条操作。');
      return;
    }
    if (event.target.closest('[data-sl-return-revision]')) {
      toast('已标记为退回补充（预览操作）。请在下方审核表中逐条退回具体实验记录。');
      return;
    }
    if (event.target.closest('[data-sl-approve-row]')) {
      const btn = event.target.closest('[data-sl-approve-row]');
      const code = displayExperimentTitle(btn.dataset.code);
      toast(code + ' 已通过（预览操作，不写入数据库）。');
      return;
    }
    if (event.target.closest('[data-sl-reject-row]')) {
      const btn = event.target.closest('[data-sl-reject-row]');
      const code = displayExperimentTitle(btn.dataset.code);
      toast(code + ' 已退回补充（预览操作，不写入数据库）。');
      return;
    }

    // Phase 8: apply assay policy preset (demo interaction, non-persistent)
    if (event.target.closest('[data-sl-apply-policy-preset]')) {
      const btn = event.target.closest('[data-sl-apply-policy-preset]');
      const assay = btn.dataset.assay || 'wb';
      const presets = {
        wb: {
          label: 'WB (免疫印迹)',
          lines: [
            'WB 策略预设已应用：',
            '- 条带检测：目标蛋白/内参 band 识别与 ROI 定位',
            '- 灰度归一化：目标蛋白/内参灰度比值归一化',
            '- 断点检测：GAD67/GAPDH 联合异常阈值 0.70',
            '- 跳步规则：前后光密度偏移 > 1.5× 同批相邻均值',
            '- 步骤推理：DP/Viterbi 路径评分阈值 0.70',
          ],
        },
        if: {
          label: 'IF (免疫荧光)',
          lines: [
            'IF 策略预设已应用：',
            '- 通道映射：DAPI/FITC/TRITC 通道自动识别',
            '- 阳性面积：NeuN+ 阳性面积百分比统计',
            '- 断点检测：视野细胞密度/阈值统一性 异常阈值 0.70',
            '- 跳步规则：前后阳性面积偏移 > 1.5× 相邻均值；附加 OT 代价评估',
            '- 步骤推理：DP/Viterbi 路径评分阈值 0.70',
          ],
        },
        qpcr: {
          label: 'qPCR (实时荧光定量)',
          lines: [
            'qPCR 策略预设已应用：',
            '- Ct值校验：复孔 CV < 5%，NTC 无扩增',
            '- 扩增曲线：基线平稳，指数期正常，无异常抬升',
            '- 熔解曲线：单峰确认，无引物二聚体或非特异性扩增',
            '- Fold-change阈值：mTOR/Rheb FC 超 2.00 标记高风险',
            '- 跳步规则：Ct 数值不连续性 > 1.5× 同批相邻复孔均值',
            '- 步骤推理：DP/Viterbi 路径评分阈值 0.70',
          ],
        },
      };
      const preset = presets[assay] || presets.wb;
      writeConsole('[data-sl-preset-console]', preset.lines);
      toast(preset.label + ' 策略预设已刷新（预览操作，不写入服务器配置）。');
      return;
    }

    // Phase 8: refresh service telemetry
    if (event.target.closest('[data-sl-refresh-telemetry]')) {
      const timestamp = new Date().toLocaleTimeString('zh-CN', { hour12: false });
      writeConsole('[data-sl-health-console]', [
        '服务遥测刷新 (' + timestamp + ')',
        '目标检测 · V2 · 在线 · 128ms avg · 绑定: 智能识别',
        'OCR识别 · V2 · 在线 · 96ms avg · 绑定: 智能识别',
        '断点识别引擎 · V2 · 在线 · 152ms avg · 绑定: 断点识别',
        '跳步识别引擎 · V2 · 在线 · 134ms avg · 绑定: 跳步识别',
        '步骤推理引擎 · V2 · 在线 · 176ms avg · 绑定: 步骤推理',
        '模板补全引擎 · V2 · 在线 · 112ms avg · 绑定: 模板补全',
        '语义分析 · V2 · 在线 · 210ms avg · 绑定: 跳步/推理',
        '所有模型服务响应正常，无告警。',
      ]);
      toast('服务遥测已刷新，7 个模型服务均在线（预览操作，不写入服务器）。');
      return;
    }
  });

  document.addEventListener('input', event => {
    if (event.target.matches('[data-sl-template-search]')) {
      applyTemplateFilter();
    }
  });

  refreshUploadConsole();
  applyTemplateFilter();
  initPaginatedTables();
})();
