(function () {
  const byId = (id) => document.getElementById(id);
  const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
  const format = (value, digits = 2) => Number(value).toFixed(digits).replace(/\.?0+$/, '');
  const defaultRoi = (label = 'ROI 1') => ({ x: 0.15, y: 0.15, w: 0.7, h: 0.7, label });
  const state = {
    images: [],
    activeImageIndex: -1,
    drag: null,
    runHistory: [],
  };

  const readJsonResponse = async (response) => {
    const raw = await response.text();
    if (!raw.trim()) {
      throw new Error(`Empty response body (${response.status})`);
    }
    try {
      return JSON.parse(raw);
    } catch (_error) {
      throw new Error(`Non-JSON response (${response.status}): ${raw.slice(0, 160)}`);
    }
  };

  const buildCompressedDataUrl = async (dataUrl, maxEdge = 1800, quality = 0.9) => {
    const image = await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('无法压缩图像。'));
      img.src = dataUrl;
    });
    const scale = Math.min(1, maxEdge / Math.max(image.width, image.height, 1));
    const width = Math.max(1, Math.round(image.width * scale));
    const height = Math.max(1, Math.round(image.height * scale));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    context.drawImage(image, 0, 0, width, height);
    return canvas.toDataURL('image/jpeg', quality);
  };

  const getActiveImage = () => state.images[state.activeImageIndex] || null;
  const getActiveRoi = () => {
    const image = getActiveImage();
    if (!image) return null;
    return image.rois[image.activeRoiIndex] || null;
  };

  const setStatus = (text, ok) => {
    const node = byId('imagej-studio-status');
    if (!node) return;
    node.textContent = text;
    node.classList.remove('text-success', 'text-danger', 'smallgray');
    node.classList.add(ok === true ? 'text-success' : ok === false ? 'text-danger' : 'smallgray');
  };

  const readFile = async (file) => {
    const dataUrl = await new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ''));
      reader.onerror = () => reject(new Error(`无法读取 ${file.name}`));
      reader.readAsDataURL(file);
    });
    const image = await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error(`无法解析 ${file.name}`));
      img.src = dataUrl;
    });
    return {
      name: file.name,
      dataUrl,
      width: image.width,
      height: image.height,
      rois: [defaultRoi()],
      activeRoiIndex: 0,
      lastResult: null,
    };
  };

  const syncImageList = () => {
    const select = byId('imagej-studio-image-select');
    const list = byId('imagej-studio-image-list');
    if (!select || !list) return;
    select.replaceChildren();
    list.replaceChildren();
    if (!state.images.length) {
      select.appendChild(new Option('尚未导入图像', ''));
      const empty = document.createElement('div');
      empty.className = 'list-group-item smallgray';
      empty.textContent = '暂无图像';
      list.appendChild(empty);
      return;
    }
    state.images.forEach((image, index) => {
      const option = new Option(`${index + 1}. ${image.name}`, String(index), false, index === state.activeImageIndex);
      select.appendChild(option);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `list-group-item list-group-item-action ${index === state.activeImageIndex ? 'active' : ''}`;
      button.textContent = `${index + 1}. ${image.name}`;
      button.addEventListener('click', () => {
        state.activeImageIndex = index;
        syncImageList();
        syncRoiUi();
        renderPreview();
      });
      list.appendChild(button);
    });
  };

  const syncRoiUi = () => {
    const image = getActiveImage();
    const roiSelect = byId('imagej-studio-roi-select');
    const labelInput = byId('imagej-studio-roi-label');
    const xInput = byId('imagej-studio-roi-x');
    const yInput = byId('imagej-studio-roi-y');
    const wInput = byId('imagej-studio-roi-w');
    const hInput = byId('imagej-studio-roi-h');
    if (!roiSelect || !labelInput || !xInput || !yInput || !wInput || !hInput) return;
    roiSelect.replaceChildren();
    if (!image) {
      roiSelect.appendChild(new Option('ROI 1', '0'));
      labelInput.value = 'ROI 1';
      xInput.value = '15';
      yInput.value = '15';
      wInput.value = '70';
      hInput.value = '70';
      return;
    }
    image.rois.forEach((roi, index) => {
      roiSelect.appendChild(new Option(roi.label || `ROI ${index + 1}`, String(index), false, index === image.activeRoiIndex));
    });
    const roi = getActiveRoi();
    if (!roi) return;
    labelInput.value = roi.label || '';
    xInput.value = format(roi.x * 100, 1);
    yInput.value = format(roi.y * 100, 1);
    wInput.value = format(roi.w * 100, 1);
    hInput.value = format(roi.h * 100, 1);
  };

  const drawRoi = (ctx, canvas, roi, active) => {
    const x = roi.x * canvas.width;
    const y = roi.y * canvas.height;
    const w = roi.w * canvas.width;
    const h = roi.h * canvas.height;
    ctx.save();
    ctx.strokeStyle = active ? '#d9480f' : '#1d4ed8';
    ctx.lineWidth = active ? 3 : 2;
    ctx.setLineDash(active ? [] : [8, 6]);
    ctx.strokeRect(x, y, w, h);
    ctx.fillStyle = active ? 'rgba(217, 72, 15, 0.14)' : 'rgba(29, 78, 216, 0.10)';
    ctx.fillRect(x, y, w, h);
    ctx.fillStyle = active ? '#9a3412' : '#1e3a8a';
    ctx.font = '600 13px sans-serif';
    ctx.fillText(roi.label || 'ROI', x + 8, Math.max(y + 18, 18));
    ctx.restore();
  };

  const renderPreview = () => {
    const image = getActiveImage();
    const canvas = byId('imagej-studio-preview');
    const empty = byId('imagej-studio-preview-empty');
    const meta = byId('imagej-studio-preview-meta');
    if (!canvas || !empty || !meta) return;
    if (!image) {
      canvas.classList.add('d-none');
      empty.classList.remove('d-none');
      meta.textContent = '';
      return;
    }
    const preview = new Image();
    preview.onload = () => {
      const ratio = preview.width > 0 ? preview.height / preview.width : 1;
      canvas.width = 960;
      canvas.height = Math.round(canvas.width * ratio);
      const ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(preview, 0, 0, canvas.width, canvas.height);
      image.rois.forEach((roi, index) => drawRoi(ctx, canvas, roi, index === image.activeRoiIndex));
      canvas.classList.remove('d-none');
      empty.classList.add('d-none');
      meta.textContent = `${image.name} | ${image.width}x${image.height}px | ${image.rois.length} ROI`;
    };
    preview.src = image.dataUrl;
  };

  const applyRoiInputs = () => {
    const roi = getActiveRoi();
    if (!roi) return;
    roi.label = byId('imagej-studio-roi-label')?.value.trim() || roi.label || `ROI ${getActiveImage().activeRoiIndex + 1}`;
    roi.x = clamp((parseFloat(byId('imagej-studio-roi-x')?.value || '15') || 15) / 100, 0, 0.98);
    roi.y = clamp((parseFloat(byId('imagej-studio-roi-y')?.value || '15') || 15) / 100, 0, 0.98);
    roi.w = clamp((parseFloat(byId('imagej-studio-roi-w')?.value || '70') || 70) / 100, 0.02, 1 - roi.x);
    roi.h = clamp((parseFloat(byId('imagej-studio-roi-h')?.value || '70') || 70) / 100, 0.02, 1 - roi.y);
    syncRoiUi();
    renderPreview();
  };

  const pointToRoiCoords = (event, canvas) => {
    const rect = canvas.getBoundingClientRect();
    return {
      x: clamp((event.clientX - rect.left) / rect.width, 0, 1),
      y: clamp((event.clientY - rect.top) / rect.height, 0, 1),
    };
  };

  const bindPreview = () => {
    const canvas = byId('imagej-studio-preview');
    if (!canvas) return;
    canvas.addEventListener('mousedown', (event) => {
      const image = getActiveImage();
      if (!image) return;
      const point = pointToRoiCoords(event, canvas);
      const roi = getActiveRoi();
      if (!roi) return;
      const inside = point.x >= roi.x && point.x <= roi.x + roi.w && point.y >= roi.y && point.y <= roi.y + roi.h;
      if (inside) {
        state.drag = { mode: 'move', startX: point.x, startY: point.y, roi: { ...roi } };
        return;
      }
      state.drag = { mode: 'draw', startX: point.x, startY: point.y };
      image.rois[image.activeRoiIndex] = { x: point.x, y: point.y, w: 0.02, h: 0.02, label: roi.label || `ROI ${image.activeRoiIndex + 1}` };
      syncRoiUi();
      renderPreview();
    });
    canvas.addEventListener('mousemove', (event) => {
      const image = getActiveImage();
      const drag = state.drag;
      if (!image || !drag) return;
      const point = pointToRoiCoords(event, canvas);
      const roi = image.rois[image.activeRoiIndex];
      if (!roi) return;
      if (drag.mode === 'move' && drag.roi) {
        roi.x = clamp(drag.roi.x + (point.x - drag.startX), 0, 1 - drag.roi.w);
        roi.y = clamp(drag.roi.y + (point.y - drag.startY), 0, 1 - drag.roi.h);
      } else {
        const left = Math.min(drag.startX, point.x);
        const top = Math.min(drag.startY, point.y);
        const right = Math.max(drag.startX, point.x);
        const bottom = Math.max(drag.startY, point.y);
        roi.x = left;
        roi.y = top;
        roi.w = clamp(right - left, 0.02, 1 - left);
        roi.h = clamp(bottom - top, 0.02, 1 - top);
      }
      syncRoiUi();
      renderPreview();
    });
    window.addEventListener('mouseup', () => {
      state.drag = null;
    });
  };

  const fetchSystemInfo = async () => {
    const bridgeNode = byId('imagej-studio-bridge-status');
    const engineNode = byId('imagej-studio-engine');
    const macroNode = byId('imagej-studio-macro-count');
    const pluginNode = byId('imagej-studio-plugin-count');
    const detailsNode = byId('imagej-studio-worker-details');
    try {
      const pingResponse = await fetch('/smartlab-imagej.php?action=ping', { credentials: 'same-origin' });
      const pingPayload = await readJsonResponse(pingResponse);
      const infoResponse = await fetch('/smartlab-imagej.php?action=info', { credentials: 'same-origin' });
      const infoPayload = await readJsonResponse(infoResponse);
      bridgeNode.textContent = pingPayload.ok ? 'Connected' : 'Error';
      bridgeNode.className = `h4 mb-1 ${pingPayload.ok ? 'text-success' : 'text-danger'}`;
      engineNode.textContent = 'fiji-macro-ihc-v1';
      macroNode.textContent = String((infoPayload.macros || []).length);
      pluginNode.textContent = String((infoPayload.plugins || []).length);
      detailsNode.textContent = `Fiji: ${infoPayload.fiji_binary_exists ? 'ready' : 'missing'} | app: ${infoPayload.fiji_app_dir_exists ? infoPayload.macro_dir : 'not found'} | workdir: ${infoPayload.work_dir || '-'}`;
    } catch (error) {
      bridgeNode.textContent = 'Offline';
      bridgeNode.className = 'h4 mb-1 text-danger';
      engineNode.textContent = 'Unavailable';
      detailsNode.textContent = error.message || '无法获取 worker 状态。';
    }
  };

  const renderResults = () => {
    const body = byId('imagej-studio-results-body');
    const raw = byId('imagej-studio-raw-json');
    const rows = state.runHistory.flatMap((entry) => (entry.results || []).map((row) => ({ image: entry.image, row })));
    if (!body || !raw) return;
    body.replaceChildren();
    if (!rows.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 7;
      td.className = 'smallgray';
      td.textContent = '尚未返回分析结果。';
      tr.appendChild(td);
      body.appendChild(tr);
      raw.value = '暂无输出。';
      return;
    }
    rows.forEach(({ image, row }) => {
      const tr = document.createElement('tr');
      [image, row.label, format(row.positive_ratio, 2), String(row.intensity_score), format(row.h_score, 1), String(row.irs), format(row.tissue_ratio, 1)].forEach((value) => {
        const td = document.createElement('td');
        td.textContent = value;
        tr.appendChild(td);
      });
      body.appendChild(tr);
    });
    raw.value = JSON.stringify(state.runHistory, null, 2);
  };

  const updateRunLog = () => {
    const node = byId('imagej-studio-run-log');
    if (!node) return;
    if (!state.runHistory.length) {
      node.textContent = '尚未执行。';
      return;
    }
    const last = state.runHistory[state.runHistory.length - 1];
    node.textContent = `${last.image}: ${last.ok ? 'ok' : 'failed'} | ${last.results.length} ROI | ${last.engine || '-'}`;
  };

  const buildPayload = (image) => ({
    mode: byId('imagej-studio-mode')?.value || 'deconv',
    threshold: Number(byId('imagej-studio-threshold')?.value || '32'),
    he_threshold: Number(byId('imagej-studio-he-threshold')?.value || '24'),
    rois: image.rois.map((roi, index) => ({
      label: roi.label || `ROI ${index + 1}`,
      x: Math.round(roi.x * image.width),
      y: Math.round(roi.y * image.height),
      w: Math.round(roi.w * image.width),
      h: Math.round(roi.h * image.height),
    })),
  });

  const runImageJ = async () => {
    const image = getActiveImage();
    if (!image) {
      setStatus('请先导入图像。', false);
      return;
    }
    const mode = byId('imagej-studio-batch-mode')?.value || 'current';
    const targets = mode === 'all' ? state.images : [image];
    setStatus(`正在向 ImageJ worker 提交 ${targets.length} 张图像...`, null);
    state.runHistory = [];
    for (const target of targets) {
      const compressedDataUrl = await buildCompressedDataUrl(target.dataUrl);
      const response = await fetch('/smartlab-imagej.php?action=ihc_analyze', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          ...buildPayload(target),
          image_base64: compressedDataUrl,
        }),
      });
      const payload = await readJsonResponse(response);
      target.lastResult = payload;
      state.runHistory.push({
        image: target.name,
        ok: !!payload.ok,
        engine: payload.engine || '',
        results: Array.isArray(payload.results) ? payload.results : [],
        stdout: payload.stdout || '',
        stderr: payload.stderr || '',
        error: payload.error || '',
      });
      if (!response.ok || !payload.ok) {
        throw new Error(`${target.name}: ${payload.error || payload.stderr || `ImageJ failed (${response.status})`}`);
      }
    }
    renderResults();
    updateRunLog();
    setStatus(`ImageJ 远程分析完成，已处理 ${targets.length} 张图像。`, true);
  };

  document.addEventListener('DOMContentLoaded', () => {
    if (!byId('imagej-studio-file')) return;
    fetchSystemInfo();
    bindPreview();
    byId('imagej-studio-file')?.addEventListener('change', async (event) => {
      const files = Array.from(event.currentTarget.files || []);
      if (!files.length) return;
      const loaded = [];
      for (const file of files) {
        loaded.push(await readFile(file));
      }
      state.images.push(...loaded);
      if (state.activeImageIndex < 0) state.activeImageIndex = 0;
      syncImageList();
      syncRoiUi();
      renderPreview();
      setStatus(`已导入 ${loaded.length} 张图像。`, true);
      event.currentTarget.value = '';
    });
    byId('imagej-studio-image-select')?.addEventListener('change', (event) => {
      state.activeImageIndex = parseInt(event.currentTarget.value || '0', 10) || 0;
      syncImageList();
      syncRoiUi();
      renderPreview();
    });
    byId('imagej-studio-roi-select')?.addEventListener('change', (event) => {
      const image = getActiveImage();
      if (!image) return;
      image.activeRoiIndex = parseInt(event.currentTarget.value || '0', 10) || 0;
      syncRoiUi();
      renderPreview();
    });
    ['imagej-studio-roi-label', 'imagej-studio-roi-x', 'imagej-studio-roi-y', 'imagej-studio-roi-w', 'imagej-studio-roi-h'].forEach((id) => {
      byId(id)?.addEventListener('input', applyRoiInputs);
    });
    byId('imagej-studio-add-roi')?.addEventListener('click', () => {
      const image = getActiveImage();
      if (!image) return;
      const index = image.rois.length + 1;
      image.rois.push(defaultRoi(`ROI ${index}`));
      image.activeRoiIndex = image.rois.length - 1;
      syncRoiUi();
      renderPreview();
    });
    byId('imagej-studio-delete-roi')?.addEventListener('click', () => {
      const image = getActiveImage();
      if (!image || image.rois.length <= 1) return;
      image.rois.splice(image.activeRoiIndex, 1);
      image.activeRoiIndex = clamp(image.activeRoiIndex, 0, image.rois.length - 1);
      syncRoiUi();
      renderPreview();
    });
    byId('imagej-studio-copy-roi')?.addEventListener('click', () => {
      const roi = getActiveRoi();
      if (!roi) return;
      state.images.forEach((image) => {
        image.rois = image.rois.map((entry, index) => index === image.activeRoiIndex ? { ...roi } : entry);
      });
      setStatus('已把当前 ROI 同步到全部图像的当前索引位置。', true);
    });
    byId('imagej-studio-reset-roi')?.addEventListener('click', () => {
      const image = getActiveImage();
      if (!image) return;
      image.rois[image.activeRoiIndex] = defaultRoi(image.rois[image.activeRoiIndex]?.label || `ROI ${image.activeRoiIndex + 1}`);
      syncRoiUi();
      renderPreview();
    });
    byId('imagej-studio-refresh-info')?.addEventListener('click', fetchSystemInfo);
    byId('imagej-studio-run')?.addEventListener('click', async () => {
      try {
        await runImageJ();
      } catch (error) {
        renderResults();
        updateRunLog();
        setStatus(error.message || 'ImageJ 远程分析失败。', false);
      }
    });
  });
})();
