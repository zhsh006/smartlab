(function () {
  const byId = (id) => document.getElementById(id);
  const qsa = (selector) => Array.from(document.querySelectorAll(selector));
  const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
  const formatDecimal = (value, digits) => Number(value).toFixed(digits).replace(/\.?0+$/, '');
  const getExperimentId = () => new URLSearchParams(window.location.search).get('id') || '';
  const getExperimentStorageKey = () => {
    const experimentId = getExperimentId() || 'unknown';
    return `smartlab:ihc-images:${experimentId}`;
  };

  const controlsForSelector = (selector) => {
    const direct = qsa(selector);
    if (direct.length) {
      return direct;
    }
    const nameMatch = selector.match(/^(?:input|select|textarea)\[name="([^"]+)"\]$/);
    return nameMatch ? Array.from(document.getElementsByName(nameMatch[1])) : [];
  };

  const ensureRows = (selector, count) => {
    const controls = controlsForSelector(selector);
    if (!controls.length) {
      return [];
    }
    const tbody = controls[0].closest('table')?.querySelector('tbody');
    const templateRow = tbody?.querySelector('tr:last-child');
    if (!tbody || !templateRow) {
      return controls;
    }
    while (tbody.querySelectorAll('tr').length < count) {
      const clone = templateRow.cloneNode(true);
      clone.querySelectorAll('input').forEach((input) => {
        input.value = '';
      });
      clone.querySelectorAll('select').forEach((select) => {
        select.selectedIndex = 0;
      });
      tbody.appendChild(clone);
    }
    return controlsForSelector(selector);
  };

  const fillInputs = (selector, values) => {
    const controls = ensureRows(selector, values.length);
    controls.forEach((control, index) => {
      const value = values[index] ?? '';
      if (control.tagName === 'SELECT' && value && !Array.from(control.options).some((option) => option.value === value)) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        control.appendChild(option);
      }
      control.value = value;
      control.dispatchEvent(new Event('input', { bubbles: true }));
      control.dispatchEvent(new Event('change', { bubbles: true }));
    });
  };

  const buildBars = (containerId, emptyId, rows) => {
    const container = byId(containerId);
    const empty = byId(emptyId);
    if (!container || !empty) {
      return;
    }
    if (!rows.length) {
      container.classList.add('d-none');
      container.replaceChildren();
      empty.classList.remove('d-none');
      return;
    }
    const max = Math.max(...rows.map((row) => row.value), 1);
    container.replaceChildren();
    rows.forEach((row) => {
      const node = document.createElement('div');
      node.className = 'bar';
      node.style.setProperty('--v', String(row.value));
      node.style.setProperty('--max', String(max));
      node.style.setProperty('--label', `"${row.label}: ${row.text}"`);
      container.appendChild(node);
    });
    empty.classList.add('d-none');
    container.classList.remove('d-none');
  };

  const refreshBarChart = (labelSelector, valueSelector, containerId, emptyId, digits) => {
    const labels = qsa(labelSelector);
    const values = qsa(valueSelector);
    const rows = labels.map((input, index) => {
      const label = input.value.trim();
      const value = Number((values[index]?.value || '').trim());
      if (!label || Number.isNaN(value) || value <= 0) {
        return null;
      }
      return { label, value, text: value.toFixed(digits) };
    }).filter(Boolean);
    buildBars(containerId, emptyId, rows);
  };

  const decodeImageDataUrl = async (dataUrl) => {
    const image = await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('无法解析图像。'));
      img.src = dataUrl;
    });
    const canvas = document.createElement('canvas');
    canvas.width = image.width;
    canvas.height = image.height;
    const context = canvas.getContext('2d');
    context.drawImage(image, 0, 0);
    const imageData = context.getImageData(0, 0, image.width, image.height);
    return { image, pixels: { width: image.width, height: image.height, rgba: imageData.data }, dataUrl };
  };

  const readImage = async (file) => {
    const dataUrl = await new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ''));
      reader.onerror = () => reject(new Error('无法读取图像文件。'));
      reader.readAsDataURL(file);
    });
    return decodeImageDataUrl(dataUrl);
  };

  const MIN_ROI_SIZE = 0.02;
  const ROI_HANDLE_RADIUS = 0.02;
  const getDefaultRoi = () => ({ x: 0.15, y: 0.15, w: 0.7, h: 0.7 });
  const cloneRoi = (roi) => ({ x: roi.x, y: roi.y, w: roi.w, h: roi.h });
  const cloneRoiWithLabel = (roi, fallbackLabel = '') => ({
    x: roi.x,
    y: roi.y,
    w: roi.w,
    h: roi.h,
    label: roi.label || fallbackLabel,
  });
  const clampRoi = (roi) => {
    const w = clamp(roi.w, MIN_ROI_SIZE, 1);
    const h = clamp(roi.h, MIN_ROI_SIZE, 1);
    const x = clamp(roi.x, 0, 1 - w);
    const y = clamp(roi.y, 0, 1 - h);
    return { x, y, w, h };
  };
  const clampRoiBox = (x1, y1, x2, y2) => {
    const left = clamp(Math.min(x1, x2), 0, 1);
    const top = clamp(Math.min(y1, y2), 0, 1);
    const right = clamp(Math.max(x1, x2), 0, 1);
    const bottom = clamp(Math.max(y1, y2), 0, 1);
    return clampRoi({ x: left, y: top, w: right - left, h: bottom - top });
  };
  const getRoiCorners = (roi) => ({
    left: roi.x,
    top: roi.y,
    right: roi.x + roi.w,
    bottom: roi.y + roi.h,
    centerX: roi.x + (roi.w / 2),
    centerY: roi.y + (roi.h / 2),
  });
  const resolveRoiHandle = (roi, x, y) => {
    const { left, top, right, bottom, centerX, centerY } = getRoiCorners(roi);
    const handles = [
      { mode: 'resize-nw', x: left, y: top },
      { mode: 'resize-ne', x: right, y: top },
      { mode: 'resize-sw', x: left, y: bottom },
      { mode: 'resize-se', x: right, y: bottom },
      { mode: 'resize-n', x: centerX, y: top },
      { mode: 'resize-s', x: centerX, y: bottom },
      { mode: 'resize-w', x: left, y: centerY },
      { mode: 'resize-e', x: right, y: centerY },
    ];
    return handles.find((handle) => Math.abs(x - handle.x) <= ROI_HANDLE_RADIUS && Math.abs(y - handle.y) <= ROI_HANDLE_RADIUS) || null;
  };
  const cursorForRoiMode = (mode) => {
    switch (mode) {
      case 'resize-n':
      case 'resize-s':
        return 'ns-resize';
      case 'resize-e':
      case 'resize-w':
        return 'ew-resize';
      case 'resize-ne':
      case 'resize-sw':
        return 'nesw-resize';
      case 'resize-nw':
      case 'resize-se':
        return 'nwse-resize';
      case 'draw':
        return 'crosshair';
      case 'move':
        return 'move';
      default:
        return 'default';
    }
  };
  const resizeRoiByMode = (roi, mode, dx, dy) => {
    const { left, top, right, bottom } = getRoiCorners(roi);
    let nextLeft = left;
    let nextTop = top;
    let nextRight = right;
    let nextBottom = bottom;
    if (mode.includes('w')) nextLeft += dx;
    if (mode.includes('e')) nextRight += dx;
    if (mode.includes('n')) nextTop += dy;
    if (mode.includes('s')) nextBottom += dy;
    return clampRoiBox(nextLeft, nextTop, nextRight, nextBottom);
  };
  const getRoiPixels = (pixels, roi) => {
    const xStart = Math.floor(roi.x * pixels.width);
    const yStart = Math.floor(roi.y * pixels.height);
    const xEnd = Math.floor((roi.x + roi.w) * pixels.width);
    const yEnd = Math.floor((roi.y + roi.h) * pixels.height);
    const selected = [];
    for (let y = yStart; y < yEnd; y += 1) {
      for (let x = xStart; x < xEnd; x += 1) {
        const index = (y * pixels.width) + x;
        const offset = index * 4;
        selected.push({
          index,
          r: pixels.rgba[offset] || 0,
          g: pixels.rgba[offset + 1] || 0,
          b: pixels.rgba[offset + 2] || 0,
        });
      }
    }
    return selected;
  };

  const rgbToOd = (r, g, b) => {
    const convert = (value) => -Math.log((Math.max(1, value) + 1) / 256);
    return [convert(r), convert(g), convert(b)];
  };

  const normalizedDot = (vector, basis) => (vector[0] * basis[0]) + (vector[1] * basis[1]) + (vector[2] * basis[2]);
  const percentile = (values, ratio) => {
    if (!values.length) {
      return 0;
    }
    const sorted = [...values].sort((a, b) => a - b);
    const position = clamp(ratio, 0, 1) * (sorted.length - 1);
    const lower = Math.floor(position);
    const upper = Math.ceil(position);
    if (lower === upper) {
      return sorted[lower];
    }
    const weight = position - lower;
    return (sorted[lower] * (1 - weight)) + (sorted[upper] * weight);
  };
  const getChannelValue = (pixel, mode) => {
    switch (mode) {
      case 'red':
        return pixel.r;
      case 'green':
        return pixel.g;
      case 'blue':
        return pixel.b;
      case 'rg_max':
        return Math.max(pixel.r, pixel.g);
      case 'rgb_mean':
        return (pixel.r + pixel.g + pixel.b) / 3;
      default:
        return pixel.g;
    }
  };

  const H_VECTOR = (() => {
    const raw = [0.65, 0.70, 0.29];
    const len = Math.hypot(raw[0], raw[1], raw[2]) || 1;
    return raw.map((value) => value / len);
  })();

  const DAB_VECTOR = (() => {
    const raw = [0.27, 0.57, 0.78];
    const len = Math.hypot(raw[0], raw[1], raw[2]) || 1;
    return raw.map((value) => value / len);
  })();

  const renderBasePreview = (canvasId, image, emptyId, metaId, metaText) => {
    const canvas = byId(canvasId);
    const empty = byId(emptyId);
    if (!canvas) {
      return;
    }
    const context = canvas.getContext('2d');
    canvas.width = image.width;
    canvas.height = image.height;
    context.clearRect(0, 0, image.width, image.height);
    context.drawImage(image, 0, 0);
    canvas.classList.remove('d-none');
    if (empty) {
      empty.classList.add('d-none');
    }
    const meta = byId(metaId);
    if (meta) {
      meta.textContent = metaText;
    }
  };

  const renderPixelsPreview = (canvasId, width, height, rgba, emptyId, metaId, metaText) => {
    const canvas = byId(canvasId);
    const empty = byId(emptyId);
    if (!canvas) {
      return;
    }
    const context = canvas.getContext('2d');
    canvas.width = width;
    canvas.height = height;
    const imageData = context.createImageData(width, height);
    imageData.data.set(rgba);
    context.putImageData(imageData, 0, 0);
    canvas.classList.remove('d-none');
    if (empty) {
      empty.classList.add('d-none');
    }
    const meta = byId(metaId);
    if (meta) {
      meta.textContent = metaText;
    }
  };

  const renderMaskPreview = (canvasId, emptyId, image, pixels, mask, color, metaId, metaText, roi) => {
    const canvas = byId(canvasId);
    const empty = byId(emptyId);
    if (!canvas) {
      return;
    }
    const context = canvas.getContext('2d');
    canvas.width = image.width;
    canvas.height = image.height;
    context.drawImage(image, 0, 0);
    const overlay = context.createImageData(image.width, image.height);
    for (let index = 0; index < mask.length; index += 1) {
      if (!mask[index]) {
        continue;
      }
      const offset = index * 4;
      overlay.data[offset] = color[0];
      overlay.data[offset + 1] = color[1];
      overlay.data[offset + 2] = color[2];
      overlay.data[offset + 3] = 84;
    }
    const overlayCanvas = document.createElement('canvas');
    overlayCanvas.width = image.width;
    overlayCanvas.height = image.height;
    overlayCanvas.getContext('2d').putImageData(overlay, 0, 0);
    context.drawImage(overlayCanvas, 0, 0);
    const rois = Array.isArray(roi) ? roi : (roi ? [roi] : []);
    rois.forEach((item, index) => {
      const x = item.x * image.width;
      const y = item.y * image.height;
      const w = item.w * image.width;
      const h = item.h * image.height;
      const isActive = !!item.active;
      const handleSize = Math.max(8, Math.round(Math.min(image.width, image.height) / 36));
      const halfHandle = handleSize / 2;
      const handles = [
        [x, y],
        [x + w, y],
        [x, y + h],
        [x + w, y + h],
        [x + (w / 2), y],
        [x + (w / 2), y + h],
        [x, y + (h / 2)],
        [x + w, y + (h / 2)],
      ];
      context.strokeStyle = isActive ? 'rgba(17, 24, 39, 0.95)' : 'rgba(255, 255, 255, 0.9)';
      context.lineWidth = Math.max(isActive ? 2.5 : 1.5, image.width / 320);
      context.strokeRect(x, y, w, h);
      context.fillStyle = isActive ? 'rgba(17, 24, 39, 0.92)' : 'rgba(255, 255, 255, 0.88)';
      handles.forEach(([hx, hy]) => {
        context.fillRect(hx - halfHandle, hy - halfHandle, handleSize, handleSize);
      });
      context.fillRect(x, y, 56, 18);
      context.fillStyle = isActive ? '#ffffff' : '#111827';
      context.font = `${Math.max(11, Math.round(image.width / 38))}px sans-serif`;
      context.fillText(item.label || `ROI ${index + 1}`, x + 6, y + 13);
    });
    canvas.classList.remove('d-none');
    if (empty) {
      empty.classList.add('d-none');
    }
    const meta = byId(metaId);
    if (meta) {
      meta.textContent = metaText;
    }
  };

  const setStatus = (id, text, ok) => {
    const el = byId(id);
    if (!el) {
      return;
    }
    el.textContent = text;
    el.classList.remove('smallgray', 'text-success', 'text-danger');
    el.classList.add(ok === true ? 'text-success' : ok === false ? 'text-danger' : 'smallgray');
  };

  const setImagejBridgeStatus = (text, ok) => {
    qsa('[data-smartlab-imagej-status]').forEach((node) => {
      node.textContent = text;
      node.classList.remove('text-success', 'text-danger', 'text-muted');
      node.classList.add(ok === true ? 'text-success' : ok === false ? 'text-danger' : 'text-muted');
    });
  };

  const checkImagejBridge = async () => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch('/smartlab-imagej.php?action=ping', {
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': csrfToken,
        },
      });
      if (!response.ok) {
        setImagejBridgeStatus(`unavailable (${response.status})`, false);
        return;
      }
      const payload = await response.json();
      setImagejBridgeStatus(payload.ok ? 'connected' : 'error', !!payload.ok);
    } catch (_error) {
      setImagejBridgeStatus('offline', false);
    }
  };

  const exportCanvas = (canvasId, filename) => {
    const canvas = byId(canvasId);
    if (!canvas) {
      return false;
    }
    canvas.toBlob((blob) => {
      if (!blob) {
        return;
      }
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }, 'image/png');
    return true;
  };

  const state = {
    wb: null,
    wbPairs: [],
    wbSingleSignals: {
      target: null,
      reference: null,
    },
    wbPairDraft: {},
    ifp: null,
    ihc: null,
    drag: null,
  };

  const getActiveIhcEntry = () => {
    if (!state.ihc) {
      return null;
    }
    if (Array.isArray(state.ihc.images)) {
      return state.ihc.images[state.ihc.activeImageIndex] || null;
    }
    return state.ihc;
  };

  const getCompareIhcEntry = () => {
    if (!state.ihc || !Array.isArray(state.ihc.images)) {
      return null;
    }
    const index = Number.isInteger(state.ihc.compareImageIndex) ? state.ihc.compareImageIndex : -1;
    if (index < 0) {
      return null;
    }
    return state.ihc.images[index] || null;
  };

  const getIhcOps = () => ({
    viewMode: byId('ihc-analysis-view-mode')?.value || 'original',
    brightness: clamp(parseInt(byId('ihc-analysis-brightness')?.value || '0', 10) || 0, -100, 100),
    contrast: clamp(parseInt(byId('ihc-analysis-contrast')?.value || '100', 10) || 100, 20, 300),
    invertView: !!byId('ihc-analysis-invert-view')?.checked,
  });

  const transformPixelLinear = (value, brightness, contrast) => clamp((((value - 128) * (contrast / 100)) + 128 + brightness), 0, 255);

  const buildIhcPreviewRgba = (pixels, ops) => {
    const rgba = new Uint8ClampedArray(pixels.rgba.length);
    for (let offset = 0; offset < pixels.rgba.length; offset += 4) {
      const r = pixels.rgba[offset] || 0;
      const g = pixels.rgba[offset + 1] || 0;
      const b = pixels.rgba[offset + 2] || 0;
      let outR = r;
      let outG = g;
      let outB = b;

      if (ops.viewMode === 'grayscale') {
        const lum = (0.299 * r) + (0.587 * g) + (0.114 * b);
        outR = lum;
        outG = lum;
        outB = lum;
      } else if (ops.viewMode === 'dab') {
        const [odR, odG, odB] = rgbToOd(r, g, b);
        const dabScore = clamp(normalizedDot([odR, odG, odB], DAB_VECTOR) * 120, 0, 255);
        outR = 255 - (dabScore * 0.15);
        outG = 230 - (dabScore * 0.45);
        outB = 190 - (dabScore * 0.75);
      } else if (ops.viewMode === 'hematoxylin') {
        const [odR, odG, odB] = rgbToOd(r, g, b);
        const heScore = clamp(normalizedDot([odR, odG, odB], H_VECTOR) * 120, 0, 255);
        outR = 215 - (heScore * 0.55);
        outG = 225 - (heScore * 0.35);
        outB = 255 - (heScore * 0.05);
      }

      outR = transformPixelLinear(outR, ops.brightness, ops.contrast);
      outG = transformPixelLinear(outG, ops.brightness, ops.contrast);
      outB = transformPixelLinear(outB, ops.brightness, ops.contrast);

      if (ops.invertView) {
        outR = 255 - outR;
        outG = 255 - outG;
        outB = 255 - outB;
      }

      rgba[offset] = Math.round(outR);
      rgba[offset + 1] = Math.round(outG);
      rgba[offset + 2] = Math.round(outB);
      rgba[offset + 3] = 255;
    }
    return rgba;
  };

  const renderIhcOriginalPanel = () => {
    const current = getActiveIhcEntry();
    if (!current) {
      return;
    }
    const ops = getIhcOps();
    const previewRgba = buildIhcPreviewRgba(current.pixels, ops);
    const modeLabel = {
      original: 'Original',
      grayscale: 'Grayscale',
      dab: 'DAB map',
      hematoxylin: 'Hematoxylin map',
    }[ops.viewMode] || ops.viewMode;
    renderPixelsPreview(
      'smartlab-ihc-original-preview',
      current.pixels.width,
      current.pixels.height,
      previewRgba,
      'smartlab-ihc-original-preview-empty',
      'smartlab-ihc-original-preview-meta',
      `${current.sourceName || 'local image'} · ${current.pixels.width}×${current.pixels.height}px，视图 ${modeLabel}，brightness ${ops.brightness}，contrast ${ops.contrast}%${ops.invertView ? '，invert' : ''}。`,
    );
    const compare = getCompareIhcEntry();
    if (compare) {
      const compareRgba = buildIhcPreviewRgba(compare.pixels, ops);
      renderPixelsPreview(
        'smartlab-ihc-compare-preview',
        compare.pixels.width,
        compare.pixels.height,
        compareRgba,
        'smartlab-ihc-compare-preview-empty',
        'smartlab-ihc-compare-preview-meta',
        `${compare.sourceName || 'compare image'} · ${compare.pixels.width}×${compare.pixels.height}px`,
      );
    } else {
      const compareCanvas = byId('smartlab-ihc-compare-preview');
      const compareEmpty = byId('smartlab-ihc-compare-preview-empty');
      const compareMeta = byId('smartlab-ihc-compare-preview-meta');
      if (compareCanvas) compareCanvas.classList.add('d-none');
      if (compareEmpty) compareEmpty.classList.remove('d-none');
      if (compareMeta) compareMeta.textContent = '';
    }
  };

  const getCollectionState = (kind) => (kind === 'if' ? state.ifp : getActiveIhcEntry());
  const getCollectionLabel = (kind, index) => (kind === 'if' ? `Field ${index + 1}` : `Region ${index + 1}`);
  const getCollectionInputs = (kind) => kind === 'if'
    ? {
        label: 'if-analysis-field-label',
        select: 'if-analysis-roi-select',
        summary: 'smartlab-if-roi-summary',
      }
    : {
        label: 'ihc-analysis-region-label',
        select: 'ihc-analysis-roi-select',
        summary: 'smartlab-ihc-roi-summary',
      };

  const syncRoiControls = (kind) => {
    const current = getCollectionState(kind);
    const ids = getCollectionInputs(kind);
    const select = byId(ids.select);
    const labelInput = byId(ids.label);
    const summary = byId(ids.summary);
    if (!current) {
      if (select) select.replaceChildren();
      if (labelInput) labelInput.value = '';
      if (summary) summary.textContent = '尚未导入图像。';
      return;
    }
    if (select) {
      select.replaceChildren();
      current.rois.forEach((roi, index) => {
        const option = document.createElement('option');
        option.value = String(index);
        option.textContent = roi.label || getCollectionLabel(kind, index);
        if (index === current.activeIndex) option.selected = true;
        select.appendChild(option);
      });
    }
    if (labelInput) {
      labelInput.value = current.rois[current.activeIndex]?.label || getCollectionLabel(kind, current.activeIndex);
    }
    if (summary) {
      const prefix = kind === 'ihc' && state.ihc?.images ? `${current.sourceName || 'current image'} | ` : '';
      summary.textContent = `${prefix}当前 ${current.rois.length} 个 ROI，正在编辑 ${current.rois[current.activeIndex]?.label || getCollectionLabel(kind, current.activeIndex)}。`;
    }
  };

  const addRoi = (kind, rerender) => {
    const current = getCollectionState(kind);
    if (!current) return;
    const base = current.rois[current.activeIndex] || getDefaultRoi();
    const next = clampRoi({
      x: base.x + 0.04,
      y: base.y + 0.04,
      w: base.w * 0.82,
      h: base.h * 0.82,
    });
    current.rois.push({ ...next, label: getCollectionLabel(kind, current.rois.length) });
    current.activeIndex = current.rois.length - 1;
    syncRoiControls(kind);
    rerender();
  };

  const getIhcRoiTemplate = () => {
    const current = getActiveIhcEntry();
    if (!current?.rois?.length) {
      return [{ ...getDefaultRoi(), label: 'Whole region' }];
    }
    return current.rois.map((roi, index) => cloneRoiWithLabel(roi, getCollectionLabel('ihc', index)));
  };

  const applyIhcRoiTemplateToAllImages = () => {
    if (!state.ihc?.images?.length) {
      return 0;
    }
    const template = getIhcRoiTemplate();
    state.ihc.images.forEach((entry) => {
      entry.rois = template.map((roi, index) => cloneRoiWithLabel(roi, getCollectionLabel('ihc', index)));
      entry.activeIndex = clamp(entry.activeIndex || 0, 0, entry.rois.length - 1);
    });
    syncIhcAnalysisJson();
    return state.ihc.images.length;
  };

  const syncIhcImageControls = () => {
    const select = byId('ihc-analysis-image-select');
    const compareSelect = byId('ihc-analysis-compare-select');
    const summary = byId('smartlab-ihc-image-summary');
    if (!state.ihc || !Array.isArray(state.ihc.images)) {
      if (select) select.replaceChildren();
      if (compareSelect) compareSelect.replaceChildren();
      if (summary) summary.textContent = '当前 0 张图像。';
      return;
    }
    if (select) {
      select.replaceChildren();
      state.ihc.images.forEach((entry, index) => {
        const option = document.createElement('option');
        option.value = String(index);
        option.textContent = entry.sourceName || `Image ${index + 1}`;
        if (index === state.ihc.activeImageIndex) option.selected = true;
        select.appendChild(option);
      });
    }
    if (compareSelect) {
      compareSelect.replaceChildren();
      const emptyOption = document.createElement('option');
      emptyOption.value = '';
      emptyOption.textContent = '不对比';
      if (!Number.isInteger(state.ihc.compareImageIndex) || state.ihc.compareImageIndex < 0) {
        emptyOption.selected = true;
      }
      compareSelect.appendChild(emptyOption);
      state.ihc.images.forEach((entry, index) => {
        if (index === state.ihc.activeImageIndex) {
          return;
        }
        const option = document.createElement('option');
        option.value = String(index);
        option.textContent = entry.sourceName || `Image ${index + 1}`;
        if (index === state.ihc.compareImageIndex) option.selected = true;
        compareSelect.appendChild(option);
      });
    }
    if (summary) {
      const current = getActiveIhcEntry();
      summary.textContent = `当前 ${state.ihc.images.length} 张图像，正在分析 ${current?.sourceName || 'current image'}。`;
    }
    syncIhcAnalysisJson();
  };

  const collectIhcResultRows = () => {
    const labels = qsa('input[name="ihc_result_region_label[]"]');
    const positives = qsa('input[name="ihc_result_positive_ratio[]"]');
    const intensities = qsa('input[name="ihc_result_intensity_score[]"]');
    const hScores = qsa('input[name="ihc_result_h_score[]"]');
    const irsScores = qsa('input[name="ihc_result_irs_score[]"]');
    const notes = qsa('input[name="ihc_result_note[]"]');
    return labels.map((input, index) => ({
      label: input.value || '',
      positiveRatio: positives[index]?.value || '',
      intensityScore: intensities[index]?.value || '',
      hScore: hScores[index]?.value || '',
      irs: irsScores[index]?.value || '',
      note: notes[index]?.value || '',
    })).filter((row) => Object.values(row).some((value) => `${value}`.trim() !== ''));
  };

  const storeIhcResultSnapshot = (entry, source = 'local') => {
    if (!entry) {
      return;
    }
    entry.resultSnapshot = {
      source,
      rows: collectIhcResultRows(),
      positiveArea: document.querySelector('input[name="ihc_positive_area"]')?.value || '',
      staining: document.querySelector('input[name="ihc_staining_intensity"]')?.value || '',
      hScore: document.querySelector('input[name="ihc_h_score"]')?.value || '',
    };
    syncIhcAnalysisJson();
  };

  const applyIhcResultSnapshot = (entry) => {
    const snapshot = entry?.resultSnapshot;
    if (!snapshot) {
      fillInputs('input[name="ihc_result_region_label[]"]', ['']);
      fillInputs('input[name="ihc_result_positive_ratio[]"]', ['']);
      fillInputs('input[name="ihc_result_intensity_score[]"]', ['']);
      fillInputs('input[name="ihc_result_h_score[]"]', ['']);
      fillInputs('input[name="ihc_result_irs_score[]"]', ['']);
      fillInputs('input[name="ihc_result_note[]"]', ['']);
      const positiveArea = document.querySelector('input[name="ihc_positive_area"]');
      const staining = document.querySelector('input[name="ihc_staining_intensity"]');
      const hScoreInput = document.querySelector('input[name="ihc_h_score"]');
      if (positiveArea) positiveArea.value = '';
      if (staining) staining.value = '';
      if (hScoreInput) hScoreInput.value = '';
      return;
    }
    const positiveArea = document.querySelector('input[name="ihc_positive_area"]');
    const staining = document.querySelector('input[name="ihc_staining_intensity"]');
    const hScoreInput = document.querySelector('input[name="ihc_h_score"]');
    if (positiveArea) positiveArea.value = snapshot.positiveArea || '';
    if (staining) staining.value = snapshot.staining || '';
    if (hScoreInput) hScoreInput.value = snapshot.hScore || '';
    syncIhcStructuredTable();
    syncIhcAnalysisJson();
  };

  const buildIhcImageSummaryRows = () => {
    if (!state.ihc?.images?.length) {
      return [];
    }
    return state.ihc.images.map((entry, index) => {
      const snapshot = entry.resultSnapshot || null;
      const rows = Array.isArray(snapshot?.rows) ? snapshot.rows : [];
      const positiveValues = rows.map((row) => Number(row.positiveRatio || 0)).filter((value) => !Number.isNaN(value));
      const intensityValues = rows.map((row) => Number(row.intensityScore || 0)).filter((value) => !Number.isNaN(value));
      const hScoreValues = rows.map((row) => Number(row.hScore || 0)).filter((value) => !Number.isNaN(value));
      const irsValues = rows.map((row) => Number(row.irs || 0)).filter((value) => !Number.isNaN(value));
      const avg = (values) => values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;
      return {
        label: entry.sourceName || `Image ${index + 1}`,
        positiveRatio: snapshot?.positiveArea || formatDecimal(avg(positiveValues), 2),
        intensityScore: snapshot?.staining || formatDecimal(avg(intensityValues), 1),
        hScore: snapshot?.hScore || formatDecimal(avg(hScoreValues), 1),
        irs: formatDecimal(avg(irsValues), 1),
        note: `${rows.length || 0} ROI${snapshot?.source ? ` | ${snapshot.source}` : ''}`,
      };
    });
  };

  const syncIhcStructuredTable = () => {
    const rows = buildIhcImageSummaryRows();
    if (!rows.length) {
      fillInputs('input[name="ihc_result_region_label[]"]', ['']);
      fillInputs('input[name="ihc_result_positive_ratio[]"]', ['']);
      fillInputs('input[name="ihc_result_intensity_score[]"]', ['']);
      fillInputs('input[name="ihc_result_h_score[]"]', ['']);
      fillInputs('input[name="ihc_result_irs_score[]"]', ['']);
      fillInputs('input[name="ihc_result_note[]"]', ['']);
      refreshBarChart('input[name="ihc_result_region_label[]"]', 'input[name="ihc_result_h_score[]"]', 'smartlab-ihc-chart', 'smartlab-ihc-chart-empty', 1);
      return;
    }
    fillInputs('input[name="ihc_result_region_label[]"]', rows.map((row) => row.label));
    fillInputs('input[name="ihc_result_positive_ratio[]"]', rows.map((row) => row.positiveRatio));
    fillInputs('input[name="ihc_result_intensity_score[]"]', rows.map((row) => row.intensityScore));
    fillInputs('input[name="ihc_result_h_score[]"]', rows.map((row) => row.hScore));
    fillInputs('input[name="ihc_result_irs_score[]"]', rows.map((row) => row.irs));
    fillInputs('input[name="ihc_result_note[]"]', rows.map((row) => row.note));
    refreshBarChart('input[name="ihc_result_region_label[]"]', 'input[name="ihc_result_h_score[]"]', 'smartlab-ihc-chart', 'smartlab-ihc-chart-empty', 1);
  };

  const syncIhcAnalysisJson = () => {
    const input = byId('ihc-analysis-images-json');
    if (!input || !state.ihc?.images?.length) {
      return;
    }
    input.value = JSON.stringify({
      active_image_index: state.ihc.activeImageIndex ?? 0,
      compare_image_index: state.ihc.compareImageIndex ?? -1,
      images: state.ihc.images.map((entry) => ({
        source_name: entry.sourceName || '',
        upload_id: entry.uploadId || 0,
        active_roi_index: entry.activeIndex ?? 0,
        rois: Array.isArray(entry.rois) ? entry.rois.map((roi) => ({
          label: roi.label || '',
          x: Number(roi.x ?? 0),
          y: Number(roi.y ?? 0),
          w: Number(roi.w ?? 0),
          h: Number(roi.h ?? 0),
        })) : [],
        result_snapshot: entry.resultSnapshot || null,
      })),
    });
    updateIhcUploadUsageUi();
  };

  const updateIhcUploadUsageUi = () => {
    const usedUploadIds = new Set((state.ihc?.images || []).map((entry) => Number(entry.uploadId || 0)).filter((id) => id > 0));
    qsa('#ihc-analysis-upload-select option').forEach((option) => {
      const uploadId = Number(option.value || 0);
      if (!uploadId) {
        return;
      }
      const baseName = option.dataset.uploadName || option.textContent.replace(/\s+\[used in IHC\]$/, '');
      option.dataset.uploadName = baseName;
      option.textContent = usedUploadIds.has(uploadId) ? `${baseName} [used in IHC]` : baseName;
    });
    qsa('[id^="upload-filename_"]').forEach((node) => {
      const uploadId = Number((node.id || '').replace('upload-filename_', '')) || 0;
      const parent = node.parentElement;
      if (!uploadId || !parent) {
        return;
      }
      const badgeId = `ihc-used-badge-${uploadId}`;
      const existing = byId(badgeId);
      if (usedUploadIds.has(uploadId)) {
        if (!existing) {
          const badge = document.createElement('span');
          badge.id = badgeId;
          badge.className = 'badge badge-info ml-2';
          badge.textContent = 'used in IHC';
          parent.appendChild(badge);
        }
      } else if (existing) {
        existing.remove();
      }
    });
  };

  const persistIhcImageCache = async () => {
    if (!state.ihc?.images?.length) {
      return;
    }
    const payload = {
      updated_at: new Date().toISOString(),
      images: state.ihc.images
        .filter((entry) => !!entry.dataUrl)
        .map((entry) => ({
          source_name: entry.sourceName || '',
          data_url: entry.dataUrl,
        })),
    };
    try {
      window.localStorage.setItem(getExperimentStorageKey(), JSON.stringify(payload));
    } catch (_error) {
      // Ignore storage quota failures and keep the page usable.
    }
  };

  const uploadIhcImageToEntity = async (file) => {
    const experimentId = getExperimentId();
    if (!experimentId) {
      return null;
    }
    const formData = new FormData();
    formData.append('file', file, file.name);
    formData.append('comment', `SmartLab IHC source image | ${file.name}`);
    const response = await fetch(`/api/v2/experiments/${experimentId}/uploads`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: formData,
    });
    if (!response.ok) {
      throw new Error(`实验附件上传失败 (${response.status})`);
    }
    const location = response.headers.get('Location') || '';
    const uploadIdMatch = location.match(/\/uploads\/(\d+)$/);
    return uploadIdMatch ? parseInt(uploadIdMatch[1], 10) : null;
  };

  const restoreIhcImageFromUpload = async (entry) => {
    const experimentId = getExperimentId();
    if (!experimentId || !entry?.uploadId) {
      return false;
    }
    try {
      const response = await fetch(`/api/v2/experiments/${experimentId}/uploads/${entry.uploadId}?format=binary`, {
        credentials: 'same-origin',
        headers: {
          Accept: '*/*',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      if (!response.ok) {
        return false;
      }
      const blob = await response.blob();
      const dataUrl = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result || ''));
        reader.onerror = () => reject(new Error('无法恢复附件图像。'));
        reader.readAsDataURL(blob);
      });
      const loaded = await decodeImageDataUrl(dataUrl);
      entry.image = loaded.image;
      entry.pixels = loaded.pixels;
      entry.dataUrl = loaded.dataUrl;
      return true;
    } catch (_error) {
      return false;
    }
  };

  const importIhcUploadToState = async (uploadId, uploadName) => {
    const experimentId = getExperimentId();
    if (!experimentId || !uploadId) {
      throw new Error('无法读取实验附件。');
    }
    const response = await fetch(`/api/v2/experiments/${experimentId}/uploads/${uploadId}?format=binary`, {
      credentials: 'same-origin',
      headers: {
        Accept: '*/*',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    if (!response.ok) {
      throw new Error(`读取实验附件失败 (${response.status})`);
    }
    const blob = await response.blob();
    const dataUrl = await new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ''));
      reader.onerror = () => reject(new Error('无法读取附件内容。'));
      reader.readAsDataURL(blob);
    });
    const loaded = await decodeImageDataUrl(dataUrl);
    if (!state.ihc?.images) {
      state.ihc = { images: [], activeImageIndex: 0, compareImageIndex: -1 };
    }
    const existingIndex = state.ihc.images.findIndex((entry) => Number(entry.uploadId || 0) === Number(uploadId));
    const nextEntry = {
      image: loaded.image,
      pixels: loaded.pixels,
      dataUrl: loaded.dataUrl,
      uploadId: Number(uploadId),
      rois: getIhcRoiTemplate(),
      activeIndex: 0,
      sourceName: uploadName || `Upload ${uploadId}`,
      resultSnapshot: existingIndex >= 0 ? (state.ihc.images[existingIndex].resultSnapshot || null) : null,
    };
    if (existingIndex >= 0) {
      const prev = state.ihc.images[existingIndex];
      state.ihc.images[existingIndex] = {
        ...nextEntry,
        rois: Array.isArray(prev.rois) && prev.rois.length ? prev.rois : nextEntry.rois,
        activeIndex: Number.isInteger(prev.activeIndex) ? prev.activeIndex : 0,
      };
      state.ihc.activeImageIndex = existingIndex;
    } else {
      state.ihc.images.push(nextEntry);
      state.ihc.activeImageIndex = state.ihc.images.length - 1;
      if (!Number.isInteger(state.ihc.compareImageIndex)) {
        state.ihc.compareImageIndex = -1;
      }
    }
    await persistIhcImageCache();
    syncIhcImageControls();
    syncRoiControls('ihc');
    rerenderIhc();
  };

  const restoreIhcImageCache = async () => {
    if (!state.ihc?.images?.length) {
      return false;
    }
    const raw = window.localStorage.getItem(getExperimentStorageKey());
    if (!raw) {
      return false;
    }
    try {
      const parsed = JSON.parse(raw);
      const images = Array.isArray(parsed?.images) ? parsed.images : [];
      if (!images.length) {
        return false;
      }
      let restored = 0;
      for (const entry of state.ihc.images) {
        const cached = images.find((item) => item?.source_name === entry.sourceName && typeof item?.data_url === 'string');
        if (cached) {
          const loaded = await decodeImageDataUrl(cached.data_url);
          entry.image = loaded.image;
          entry.pixels = loaded.pixels;
          entry.dataUrl = loaded.dataUrl;
          restored += 1;
          continue;
        }
        if (await restoreIhcImageFromUpload(entry)) {
          restored += 1;
        }
      }
      return restored > 0;
    } catch (_error) {
      return false;
    }
  };

  const restoreIhcAnalysisState = () => {
    const input = byId('ihc-analysis-images-json');
    if (!input || !`${input.value || ''}`.trim() || state.ihc?.images?.length) {
      return;
    }
    try {
      const parsed = JSON.parse(input.value);
      const images = Array.isArray(parsed?.images) ? parsed.images : [];
      if (!images.length) {
        return;
      }
      state.ihc = {
        images: images.map((entry, index) => ({
          image: null,
          pixels: { width: 0, height: 0, rgba: new Uint8ClampedArray() },
          rois: Array.isArray(entry?.rois) && entry.rois.length ? entry.rois.map((roi) => ({
            ...clampRoi({
              x: Number(roi?.x ?? 0.15),
              y: Number(roi?.y ?? 0.15),
              w: Number(roi?.w ?? 0.7),
              h: Number(roi?.h ?? 0.7),
            }),
            label: roi?.label || '',
          })) : [{ ...getDefaultRoi(), label: 'Whole region' }],
          activeIndex: clamp(parseInt(`${entry?.active_roi_index ?? 0}`, 10) || 0, 0, Math.max((entry?.rois?.length || 1) - 1, 0)),
          sourceName: entry?.source_name || `Image ${index + 1}`,
          uploadId: parseInt(`${entry?.upload_id ?? 0}`, 10) || 0,
          resultSnapshot: entry?.result_snapshot || null,
          dataUrl: '',
        })),
        activeImageIndex: clamp(parseInt(`${parsed?.active_image_index ?? 0}`, 10) || 0, 0, Math.max(images.length - 1, 0)),
        compareImageIndex: parsed?.compare_image_index === undefined ? -1 : parseInt(`${parsed.compare_image_index}`, 10) || -1,
      };
      syncIhcImageControls();
      syncRoiControls('ihc');
      applyIhcResultSnapshot(getActiveIhcEntry());
      setStatus('smartlab-ihc-analysis-status', '已恢复已保存的多图分析状态。正在尝试恢复本地缓存图像。', null);
    } catch (_error) {
      // Ignore malformed saved JSON and keep the page usable.
    }
  };

  const deleteRoi = (kind, rerender) => {
    const current = getCollectionState(kind);
    if (!current || current.rois.length <= 1) return;
    current.rois.splice(current.activeIndex, 1);
    current.activeIndex = clamp(current.activeIndex, 0, current.rois.length - 1);
    current.rois.forEach((roi, index) => {
      if (!roi.label) roi.label = getCollectionLabel(kind, index);
    });
    syncRoiControls(kind);
    rerender();
  };

  const analyzeIfRoi = (pixels, roi, threshold, nuclearThreshold, signalChannel, nuclearChannel) => {
    const roiPixels = getRoiPixels(pixels, roi);
    let positiveCount = 0;
    let nuclearCount = 0;
    let totalSignal = 0;
    let colocPositive = 0;
    const positiveIndexes = [];
    roiPixels.forEach((pixel) => {
      const signal = getChannelValue(pixel, signalChannel);
      const nuclear = getChannelValue(pixel, nuclearChannel);
      const isPositive = signal >= threshold;
      const isNuclear = nuclear >= nuclearThreshold;
      if (isPositive) {
        positiveCount += 1;
        totalSignal += signal;
        positiveIndexes.push(pixel.index);
      }
      if (isNuclear) {
        nuclearCount += 1;
      }
      if (isPositive && isNuclear) {
        colocPositive += 1;
      }
    });
    const roiArea = Math.max(roiPixels.length, 1);
    return {
      positiveIndexes,
      positiveRatio: (positiveCount / roiArea) * 100,
      meanIntensity: positiveCount > 0 ? totalSignal / positiveCount : 0,
      estimatedCellCount: Math.max(1, Math.round(nuclearCount / 180)),
      coloc: positiveCount > 0 ? (colocPositive / positiveCount) : 0,
      roiArea,
    };
  };

  const analyzeIhcRoi = (pixels, roi, threshold, heThreshold, mode) => {
    const roiPixels = getRoiPixels(pixels, roi);
    const scoredPixels = roiPixels.map((pixel) => {
      const [odR, odG, odB] = rgbToOd(pixel.r, pixel.g, pixel.b);
      const dabScore = clamp(normalizedDot([odR, odG, odB], DAB_VECTOR) * 120, 0, 255);
      const heScore = clamp(normalizedDot([odR, odG, odB], H_VECTOR) * 120, 0, 255);
      const brownScore = Math.max(0, pixel.r - ((pixel.g * 0.7) + (pixel.b * 0.35)));
      const luminance = (0.299 * pixel.r) + (0.587 * pixel.g) + (0.114 * pixel.b);
      const odSum = odR + odG + odB;
      const signalScore = mode === 'deconv' ? dabScore : brownScore;
      const tissueScore = mode === 'deconv' ? Math.max(dabScore, heScore) : Math.max(brownScore, heScore);
      return {
        ...pixel,
        dabScore,
        heScore,
        brownScore,
        luminance,
        odSum,
        signalScore,
        tissueScore,
        roughTissue: odSum >= 0.12 || luminance <= 235,
      };
    });
    const tissueCandidates = scoredPixels.filter((pixel) => pixel.roughTissue);
    const tissueScores = tissueCandidates.map((pixel) => pixel.tissueScore);
    const signalScores = tissueCandidates.map((pixel) => pixel.signalScore);
    const adaptiveTissueGate = Math.max(
      Math.min(heThreshold, threshold) * 0.6,
      percentile(tissueScores, 0.25) * 0.75,
      8,
    );
    const adaptivePositiveGate = Math.max(
      threshold * 0.9,
      percentile(signalScores, 0.7),
      12,
    );
    let positiveCount = 0;
    let tissueCount = 0;
    let totalSignal = 0;
    let totalHematoxylin = 0;
    const positiveIndexes = [];
    scoredPixels.forEach((pixel) => {
      const isTissue = pixel.roughTissue && pixel.tissueScore >= adaptiveTissueGate;
      const isPositive = pixel.signalScore >= adaptivePositiveGate && isTissue;
      if (isTissue) {
        tissueCount += 1;
      }
      if (isPositive) {
        positiveCount += 1;
        totalSignal += pixel.signalScore;
        totalHematoxylin += pixel.heScore;
        positiveIndexes.push(pixel.index);
      }
    });
    const roiArea = Math.max(tissueCount || roiPixels.length, 1);
    const positiveRatio = (positiveCount / roiArea) * 100;
    const meanSignal = positiveCount > 0 ? totalSignal / positiveCount : 0;
    const meanHematoxylin = positiveCount > 0 ? totalHematoxylin / positiveCount : 0;
    const intensityScore = meanSignal >= 96 ? 3 : meanSignal >= 64 ? 2 : meanSignal >= 24 ? 1 : 0;
    const hScore = positiveRatio * intensityScore;
    const percentageCategory = positiveRatio >= 80 ? 4 : positiveRatio >= 50 ? 3 : positiveRatio >= 10 ? 2 : positiveRatio > 0 ? 1 : 0;
    return {
      positiveIndexes,
      positiveRatio,
      intensityScore,
      hScore,
      irs: percentageCategory * intensityScore,
      meanSignal,
      meanHematoxylin,
      tissueRatio: (tissueCount / Math.max(roiPixels.length, 1)) * 100,
    };
  };

  const rerenderIf = () => {
    if (!state.ifp) {
      return;
    }
    const threshold = clamp(parseInt(byId('if-analysis-threshold')?.value || '160', 10) || 160, 1, 255);
    const nuclearThreshold = clamp(parseInt(byId('if-analysis-nuclear-threshold')?.value || '120', 10) || 120, 1, 255);
    const signalChannel = byId('if-analysis-signal-channel')?.value || 'green';
    const nuclearChannel = byId('if-analysis-nuclear-channel')?.value || 'blue';
    const currentRoi = state.ifp.rois[state.ifp.activeIndex];
    if (currentRoi) {
      currentRoi.label = byId('if-analysis-field-label')?.value.trim() || currentRoi.label || getCollectionLabel('if', state.ifp.activeIndex);
    }
    const { image, pixels, rois } = state.ifp;
    const mask = Array.from({ length: pixels.width * pixels.height }, () => false);
    const results = rois.map((roi, index) => {
      const label = roi.label || getCollectionLabel('if', index);
      const metrics = analyzeIfRoi(pixels, roi, threshold, nuclearThreshold, signalChannel, nuclearChannel);
      metrics.positiveIndexes.forEach((pixelIndex) => {
        mask[pixelIndex] = true;
      });
      return {
        label,
        cellCount: metrics.estimatedCellCount,
        positiveRatio: metrics.positiveRatio,
        meanIntensity: metrics.meanIntensity,
        coloc: metrics.coloc,
        note: `${signalChannel}/${nuclearChannel} ROI ${Math.round(roi.w * 100)}x${Math.round(roi.h * 100)}, signal ${threshold}, nuclear ${nuclearThreshold}`,
      };
    });
    fillInputs('input[name="if_result_field_label[]"]', results.map((row) => row.label));
    fillInputs('input[name="if_result_cell_count[]"]', results.map((row) => String(row.cellCount)));
    fillInputs('input[name="if_result_positive_ratio[]"]', results.map((row) => formatDecimal(row.positiveRatio, 2)));
    fillInputs('input[name="if_result_mean_intensity[]"]', results.map((row) => formatDecimal(row.meanIntensity, 1)));
    fillInputs('input[name="if_result_colocalization[]"]', results.map((row) => formatDecimal(row.coloc, 3)));
    fillInputs('input[name="if_result_note[]"]', results.map((row) => row.note));
    const avgPositiveRatio = results.reduce((sum, row) => sum + row.positiveRatio, 0) / Math.max(results.length, 1);
    const avgMeanIntensity = results.reduce((sum, row) => sum + row.meanIntensity, 0) / Math.max(results.length, 1);
    const avgColoc = results.reduce((sum, row) => sum + row.coloc, 0) / Math.max(results.length, 1);
    const fieldCount = document.querySelector('input[name="if_field_count"]');
    const positive = document.querySelector('input[name="if_positive_ratio"]');
    const intensity = document.querySelector('input[name="if_mean_intensity"]');
    const colocInput = document.querySelector('input[name="if_colocalization"]');
    if (fieldCount) fieldCount.value = String(results.length);
    if (positive) positive.value = formatDecimal(avgPositiveRatio, 2);
    if (intensity) intensity.value = formatDecimal(avgMeanIntensity, 1);
    if (colocInput) colocInput.value = formatDecimal(avgColoc, 3);
    renderMaskPreview('smartlab-if-preview', 'smartlab-if-preview-empty', image, pixels, mask, [42, 157, 143], 'smartlab-if-preview-meta', `${image.width}×${image.height}px，signal ${signalChannel} / nuclear ${nuclearChannel}，${results.length} 个 ROI，平均阳性比例 ${formatDecimal(avgPositiveRatio, 2)}%，平均强度 ${formatDecimal(avgMeanIntensity, 1)}。`, rois.map((roi, index) => ({ ...roi, label: roi.label || getCollectionLabel('if', index), active: index === state.ifp.activeIndex })));
    refreshBarChart('input[name="if_result_field_label[]"]', 'input[name="if_result_mean_intensity[]"]', 'smartlab-if-chart', 'smartlab-if-chart-empty', 1);
    syncRoiControls('if');
    setStatus('smartlab-if-analysis-status', `已按 ${signalChannel}/${nuclearChannel} 回填 ${results.length} 个 IF ROI，平均 positive ratio ${formatDecimal(avgPositiveRatio, 2)}%。`, true);
  };

  const rerenderIhc = () => {
    const current = getActiveIhcEntry();
    if (!current) {
      return;
    }
    const threshold = clamp(parseInt(byId('ihc-analysis-threshold')?.value || '32', 10) || 32, 1, 255);
    const heThreshold = clamp(parseInt(byId('ihc-analysis-he-threshold')?.value || '24', 10) || 24, 1, 255);
    const mode = byId('ihc-analysis-mode')?.value || 'deconv';
    const currentRoi = current.rois[current.activeIndex];
    if (currentRoi) {
      currentRoi.label = byId('ihc-analysis-region-label')?.value.trim() || currentRoi.label || getCollectionLabel('ihc', current.activeIndex);
    }
    const { image, pixels, rois } = current;
    const mask = Array.from({ length: pixels.width * pixels.height }, () => false);
    const results = rois.map((roi, index) => {
      const label = roi.label || getCollectionLabel('ihc', index);
      const metrics = analyzeIhcRoi(pixels, roi, threshold, heThreshold, mode);
      metrics.positiveIndexes.forEach((pixelIndex) => {
        mask[pixelIndex] = true;
      });
      return {
        label,
        positiveRatio: metrics.positiveRatio,
        intensityScore: metrics.intensityScore,
        hScore: metrics.hScore,
        irs: metrics.irs,
        note: `${mode === 'deconv' ? 'DAB/H' : 'brown'} ROI ${Math.round(roi.w * 100)}x${Math.round(roi.h * 100)}, DAB ${threshold}, H ${heThreshold}, tissue ${formatDecimal(metrics.tissueRatio, 1)}%`,
      };
    });
    const avgPositiveRatio = results.reduce((sum, row) => sum + row.positiveRatio, 0) / Math.max(results.length, 1);
    const avgHScore = results.reduce((sum, row) => sum + row.hScore, 0) / Math.max(results.length, 1);
    const avgIntensityScore = results.reduce((sum, row) => sum + row.intensityScore, 0) / Math.max(results.length, 1);
    const positiveArea = document.querySelector('input[name="ihc_positive_area"]');
    const staining = document.querySelector('input[name="ihc_staining_intensity"]');
    const hScoreInput = document.querySelector('input[name="ihc_h_score"]');
    if (positiveArea) positiveArea.value = formatDecimal(avgPositiveRatio, 2);
    if (staining) staining.value = formatDecimal(avgIntensityScore, 1);
    if (hScoreInput) hScoreInput.value = formatDecimal(avgHScore, 1);
    current.resultSnapshot = {
      source: 'local',
      rows: results.map((row) => ({
        label: row.label,
        positiveRatio: formatDecimal(row.positiveRatio, 2),
        intensityScore: String(row.intensityScore),
        hScore: formatDecimal(row.hScore, 1),
        irs: String(row.irs),
        note: row.note,
      })),
      positiveArea: formatDecimal(avgPositiveRatio, 2),
      staining: formatDecimal(avgIntensityScore, 1),
      hScore: formatDecimal(avgHScore, 1),
    };
    syncIhcStructuredTable();
    syncIhcAnalysisJson();
    renderIhcOriginalPanel();
    const previewCanvas = document.createElement('canvas');
    previewCanvas.width = pixels.width;
    previewCanvas.height = pixels.height;
    const previewContext = previewCanvas.getContext('2d');
    const previewImageData = previewContext.createImageData(pixels.width, pixels.height);
    previewImageData.data.set(buildIhcPreviewRgba(pixels, getIhcOps()));
    previewContext.putImageData(previewImageData, 0, 0);
    renderMaskPreview('smartlab-ihc-preview', 'smartlab-ihc-preview-empty', previewCanvas, pixels, mask, [161, 98, 7], 'smartlab-ihc-preview-meta', `${current.sourceName || 'local image'} · ${image.width}×${image.height}px，模式 ${mode === 'deconv' ? 'DAB/H' : 'brown'}，${results.length} 个 ROI，平均阳性面积 ${formatDecimal(avgPositiveRatio, 2)}%，平均 H-score ${formatDecimal(avgHScore, 1)}。`, rois.map((roi, index) => ({ ...roi, label: roi.label || getCollectionLabel('ihc', index), active: index === current.activeIndex })));
    syncRoiControls('ihc');
    syncIhcImageControls();
    setStatus('smartlab-ihc-analysis-status', `已按 ${mode === 'deconv' ? 'DAB/H' : 'brown'} 模式回填 ${results.length} 个 IHC ROI，平均 H-score ${formatDecimal(avgHScore, 1)}。`, true);
  };

  const rerenderWb = () => {
    refreshBarChart('[name="wb_result_sample_label[]"]', 'input[name="wb_result_normalized_signal[]"]', 'smartlab-wb-chart', 'smartlab-wb-chart-empty', 3);
  };

  const getWbRoi = () => state.wb?.roi || { x: 0, y: 0, w: 1, h: 1 };

  const getWbBandMode = () => byId('wb-analysis-band-mode')?.value || 'pair-target';
  const getWbActiveBand = () => (getWbBandMode() === 'pair-reference' ? 'reference' : 'target');
  const isWbCombinedBandMode = () => getWbBandMode() === 'single-combined';

  const getWbRoiBounds = (image, roi) => {
    const x1 = clamp(Math.floor(roi.x * image.width), 0, image.width - 1);
    const y1 = clamp(Math.floor(roi.y * image.height), 0, image.height - 1);
    const x2 = clamp(Math.ceil((roi.x + roi.w) * image.width), x1 + 1, image.width);
    const y2 = clamp(Math.ceil((roi.y + roi.h) * image.height), y1 + 1, image.height);
    return { x1, y1, x2, y2, width: x2 - x1, height: y2 - y1 };
  };

  const median = (values) => {
    if (!values.length) return 0;
    const sorted = values.slice().sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
  };

  const smoothProfile = (values, radius) => {
    if (!values.length || radius <= 0) {
      return values.slice();
    }
    const prefix = [0];
    values.forEach((value) => prefix.push(prefix[prefix.length - 1] + value));
    return values.map((_, index) => {
      const start = Math.max(0, index - radius);
      const end = Math.min(values.length - 1, index + radius);
      return (prefix[end + 1] - prefix[start]) / (end - start + 1);
    });
  };

  const normalizeProfile = (values) => {
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = Math.max(max - min, 1);
    return values.map((value) => (value - min) / span);
  };

  const findProfilePeaks = (values, count, minDistance) => {
    const candidates = values.map((value, index) => ({ value, index }))
      .sort((a, b) => b.value - a.value);
    const peaks = [];
    candidates.forEach((candidate) => {
      if (peaks.length >= count) return;
      if (peaks.some((peak) => Math.abs(peak.index - candidate.index) < minDistance)) return;
      peaks.push(candidate);
    });
    return peaks.sort((a, b) => a.index - b.index);
  };

  const computeWbLaneProfileFromBands = (bandCenters) => {
    if (!state.wb || !bandCenters.length) {
      return null;
    }
    const { image, grayscale, bandHeight } = state.wb;
    const bounds = getWbRoiBounds(image, getWbRoi());
    const halfBand = Math.max(2, Math.round((bandHeight * bounds.height) / 2));
    const vertical = [];
    for (let x = bounds.x1; x < bounds.x2; x += 1) {
      let total = 0;
      let count = 0;
      bandCenters.forEach((centerRatio) => {
        const centerY = Math.round(bounds.y1 + (centerRatio * bounds.height));
        const yMin = clamp(centerY - halfBand, bounds.y1, bounds.y2 - 1);
        const yMax = clamp(centerY + halfBand, bounds.y1, bounds.y2 - 1);
        for (let y = yMin; y <= yMax; y += 1) {
          total += grayscale[(y * image.width) + x] || 0;
          count += 1;
        }
      });
      vertical.push(count ? total / count : 0);
    }
    const smooth = smoothProfile(vertical, Math.max(2, Math.round(bounds.width / 140)));
    return normalizeProfile(smooth);
  };

  const medianNumber = (values, fallback = 0) => {
    const clean = values.filter((value) => Number.isFinite(value));
    return clean.length ? median(clean) : fallback;
  };

  const detectWbLaneMarks = (profile) => {
    if (!profile.length) {
      return [];
    }
    const base = median(profile);
    const max = Math.max(...profile);
    const threshold = clamp(base + ((max - base) * 0.42), 0.22, 0.68);
    const minWidth = Math.max(3, Math.round(profile.length * 0.008));
    const maxWidth = Math.max(minWidth + 1, Math.round(profile.length * 0.22));
    const components = [];
    let start = -1;
    for (let index = 0; index <= profile.length; index += 1) {
      const active = index < profile.length && profile[index] >= threshold;
      if (active && start < 0) {
        start = index;
      }
      if ((!active || index === profile.length) && start >= 0) {
        const end = index - 1;
        const width = end - start + 1;
        if (width >= minWidth && width <= maxWidth) {
          let weighted = 0;
          let total = 0;
          for (let x = start; x <= end; x += 1) {
            const weight = profile[x] || 0;
            weighted += x * weight;
            total += weight;
          }
          components.push({
            center: total ? weighted / total : (start + end) / 2,
            width,
            value: total / width,
          });
        }
        start = -1;
      }
    }
    return components.map((component) => ({
      ...component,
      centerRatio: component.center / Math.max(profile.length - 1, 1),
      widthRatio: component.width / Math.max(profile.length, 1),
    }));
  };

  const selectRegularLaneMarks = (marks, laneCount) => {
    if (marks.length <= laneCount) {
      return marks;
    }
    const sorted = marks.slice().sort((a, b) => a.centerRatio - b.centerRatio);
    let best = sorted.slice(0, laneCount);
    let bestScore = Number.POSITIVE_INFINITY;
    for (let start = 0; start <= sorted.length - laneCount; start += 1) {
      const candidate = sorted.slice(start, start + laneCount);
      const gaps = candidate.slice(1).map((mark, index) => mark.centerRatio - candidate[index].centerRatio);
      const gapMedian = medianNumber(gaps, 1 / laneCount);
      const gapScore = gaps.reduce((total, gap) => total + Math.abs(gap - gapMedian), 0);
      const strengthScore = 1 - medianNumber(candidate.map((mark) => mark.value), 0);
      const edgePenalty = candidate[0].centerRatio < 0.04 || candidate[candidate.length - 1].centerRatio > 0.96 ? 0.25 : 0;
      const score = gapScore + strengthScore + edgePenalty;
      if (score < bestScore) {
        bestScore = score;
        best = candidate;
      }
    }
    return best;
  };

  const buildWbLaneBoxesFromMarks = (marks, laneCount) => {
    const selected = selectRegularLaneMarks(marks, laneCount).sort((a, b) => a.centerRatio - b.centerRatio);
    if (!selected.length) {
      return {
        boxes: Array.from({ length: laneCount }, (_, index) => ({ x: index / laneCount, w: 1 / laneCount, inferred: true })),
        visibleCount: 0,
      };
    }
    const gaps = selected.slice(1).map((mark, index) => mark.centerRatio - selected[index].centerRatio);
    const spacing = clamp(medianNumber(gaps, 1 / Math.max(laneCount, 1)), 0.035, 0.45);
    const markWidth = medianNumber(selected.map((mark) => mark.widthRatio), spacing * 0.55);
    const boxWidth = clamp(Math.min(markWidth * 1.55, spacing * 0.78), 0.035, 0.24);
    const centers = selected.map((mark) => ({ center: mark.centerRatio, inferred: false }));
    while (centers.length < laneCount) {
      const nextRight = centers[centers.length - 1].center + spacing;
      if (nextRight <= 1 - (boxWidth / 2)) {
        centers.push({ center: nextRight, inferred: true });
        continue;
      }
      const nextLeft = centers[0].center - spacing;
      if (nextLeft >= boxWidth / 2) {
        centers.unshift({ center: nextLeft, inferred: true });
        continue;
      }
      centers.push({ center: clamp(nextRight, boxWidth / 2, 1 - (boxWidth / 2)), inferred: true });
    }
    centers.sort((a, b) => a.center - b.center);
    return {
      boxes: centers.slice(0, laneCount).map((lane) => ({
        x: clamp(lane.center - (boxWidth / 2), 0, 1 - boxWidth),
        w: boxWidth,
        inferred: lane.inferred,
      })),
      visibleCount: selected.length,
    };
  };

  const boxesToLaneDividers = (boxes) => {
    const centers = boxes.map((box) => box.x + (box.w / 2)).sort((a, b) => a - b);
    return centers.slice(0, -1).map((center, index) => (center + centers[index + 1]) / 2);
  };

  const normalizeWbLaneBoxes = (boxes) => boxes
    .map((box) => ({
      x: clamp(Number(box.x) || 0, 0, 0.98),
      w: clamp(Number(box.w) || 0.08, 0.02, 1),
      inferred: !!box.inferred,
    }))
    .map((box) => ({ ...box, x: clamp(box.x, 0, 1 - box.w) }))
    .sort((a, b) => (a.x + (a.w / 2)) - (b.x + (b.w / 2)));

  const getWbLaneBoxes = () => {
    if (state.wb?.laneBoxes?.length) {
      return normalizeWbLaneBoxes(state.wb.laneBoxes);
    }
    const dividers = state.wb?.laneDividers || [];
    const boundaries = [0, ...dividers, 1];
    return boundaries.slice(0, -1).map((start, index) => ({ x: start, w: boundaries[index + 1] - start, inferred: false }));
  };

  const computeWbProfiles = () => {
    if (!state.wb) {
      return null;
    }
    const { image, grayscale } = state.wb;
    const bounds = getWbRoiBounds(image, getWbRoi());
    const vertical = [];
    for (let x = bounds.x1; x < bounds.x2; x += 1) {
      let total = 0;
      for (let y = bounds.y1; y < bounds.y2; y += 1) {
        total += grayscale[(y * image.width) + x] || 0;
      }
      vertical.push(total / bounds.height);
    }
    const horizontal = [];
    for (let y = bounds.y1; y < bounds.y2; y += 1) {
      let total = 0;
      for (let x = bounds.x1; x < bounds.x2; x += 1) {
        total += grayscale[(y * image.width) + x] || 0;
      }
      horizontal.push(total / bounds.width);
    }
    const verticalSmooth = smoothProfile(vertical, Math.max(2, Math.round(bounds.width / 120)));
    const horizontalSmooth = smoothProfile(horizontal, Math.max(2, Math.round(bounds.height / 120)));
    return {
      bounds,
      vertical: normalizeProfile(verticalSmooth),
      horizontal: normalizeProfile(horizontalSmooth),
      rawVertical: verticalSmooth,
      rawHorizontal: horizontalSmooth,
    };
  };

  const buildWbGrayscale = (pixels, invert) => {
    const grayscale = [];
    for (let i = 0; i < pixels.width * pixels.height; i += 1) {
      const o = i * 4;
      const lum = (0.299 * pixels.rgba[o]) + (0.587 * pixels.rgba[o + 1]) + (0.114 * pixels.rgba[o + 2]);
      grayscale.push(invert ? 255 - lum : lum);
    }
    return grayscale;
  };

  const makeWbImageState = (image, pixels, sourceName, options = {}) => {
    const laneCount = clamp(Number(options.laneCount) || 4, 1, 24);
    return {
      image,
      grayscale: buildWbGrayscale(pixels, !!options.invert),
      laneDividers: Array.from({ length: laneCount - 1 }, (_, index) => (index + 1) / laneCount),
      laneBoxes: null,
      targetY: clamp(Number(options.targetY) || 0.35, 0.05, 0.95),
      referenceY: clamp(Number(options.referenceY) || 0.75, 0.05, 0.95),
      bandHeight: clamp(Number(options.bandHeight) || 0.08, 0.02, 0.25),
      controlLane: 0,
      sourceName,
      roi: { x: 0, y: 0, w: 1, h: 1 },
      profiles: null,
      confidence: null,
      hasUserRoi: false,
      hasAnalysis: false,
    };
  };

  const withTemporaryWbState = (wbState, callback) => {
    const previous = state.wb;
    state.wb = wbState;
    try {
      return callback();
    } finally {
      state.wb = previous;
    }
  };

  const autoDetectWbImageState = (wbState, laneCount, bandCenterRatio = null) => withTemporaryWbState(wbState, () => {
    const profiles = computeWbProfiles();
    if (!profiles) {
      return wbState;
    }
    const bandCenters = Number.isFinite(bandCenterRatio)
      ? [bandCenterRatio]
      : findProfilePeaks(profiles.horizontal, 1, Math.max(4, Math.round(profiles.horizontal.length * 0.18)))
        .map((peak) => peak.index / Math.max(profiles.horizontal.length - 1, 1));
    const centersForLane = bandCenters.length ? bandCenters : [wbState.targetY];
    const laneProfile = computeWbLaneProfileFromBands(centersForLane) || profiles.vertical;
    const laneMarks = detectWbLaneMarks(laneProfile);
    const laneBoxResult = buildWbLaneBoxesFromMarks(laneMarks, laneCount);
    profiles.vertical = laneProfile;
    return {
      ...wbState,
      laneBoxes: laneBoxResult.boxes,
      laneDividers: boxesToLaneDividers(laneBoxResult.boxes),
      profiles,
      confidence: { lane: clamp(laneBoxResult.visibleCount / laneCount, 0, 1), band: bandCenters.length ? 0.75 : 0.35 },
    };
  });

  const mergeWbSingleBandRows = (role, rows, sourceName) => {
    const readExistingRows = (selector) => {
      const labels = qsa('[name="wb_result_sample_label[]"]');
      return qsa(selector).map((input, index) => {
        const integrated = Number(String(input.value || '').trim());
        if (!Number.isFinite(integrated) || integrated <= 0) {
          return null;
        }
        return {
          label: labels[index]?.value || `Lane ${index + 1}`,
          signal: {
            integrated,
            background: null,
          },
        };
      }).filter(Boolean);
    };

    state.wbSingleSignals[role] = {
      sourceName: sourceName || '',
      rows: rows.map((row, index) => ({
        label: row.label || `Lane ${index + 1}`,
        signal: row.signal,
      })),
    };
    if (role !== 'target' && !state.wbSingleSignals.target) {
      const existingTargetRows = readExistingRows('input[name="wb_result_target_signal[]"]');
      if (existingTargetRows.length) {
        state.wbSingleSignals.target = { sourceName: '已保存回填表', rows: existingTargetRows };
      }
    }
    if (role !== 'reference' && !state.wbSingleSignals.reference) {
      const existingReferenceRows = readExistingRows('input[name="wb_result_reference_signal[]"]');
      if (existingReferenceRows.length) {
        state.wbSingleSignals.reference = { sourceName: '已保存回填表', rows: existingReferenceRows };
      }
    }

    const targetRows = state.wbSingleSignals.target?.rows || [];
    const referenceRows = state.wbSingleSignals.reference?.rows || [];
    const rowCount = Math.max(rows.length, targetRows.length, referenceRows.length);
    const merged = Array.from({ length: rowCount }, (_, index) => {
      const target = targetRows[index]?.signal || null;
      const reference = referenceRows[index]?.signal || null;
      const targetIntegrated = Number(target?.integrated || 0);
      const referenceIntegrated = Number(reference?.integrated || 0);
      return {
        label: targetRows[index]?.label || referenceRows[index]?.label || `Lane ${index + 1}`,
        target,
        reference,
        normalized: targetIntegrated > 0 && referenceIntegrated > 0 ? targetIntegrated / referenceIntegrated : 0,
      };
    });
    const completeRows = merged.filter((row) => Number(row.target?.integrated || 0) > 0 && Number(row.reference?.integrated || 0) > 0);
    const controlValue = completeRows.find((row) => row.label.endsWith('Lane 1'))?.normalized || completeRows[0]?.normalized || 0;

    fillInputs('[name="wb_result_sample_label[]"]', merged.map((row) => row.label));
    fillInputs('input[name="wb_result_target_signal[]"]', merged.map((row) => {
      const value = Number(row.target?.integrated || 0);
      return value > 0 ? formatDecimal(value, 1) : '';
    }));
    fillInputs('input[name="wb_result_reference_signal[]"]', merged.map((row) => {
      const value = Number(row.reference?.integrated || 0);
      return value > 0 ? formatDecimal(value, 1) : '';
    }));
    fillInputs('input[name="wb_result_normalized_signal[]"]', merged.map((row) => (
      row.normalized > 0 ? formatDecimal(row.normalized, 3) : ''
    )));
    fillInputs('[name="wb_result_trend[]"]', merged.map((row) => {
      if (!controlValue || row.normalized <= 0) return '';
      const ratio = row.normalized / controlValue;
      return ratio > 1.15 ? 'up' : ratio < 0.85 ? 'down' : 'stable';
    }));
    fillInputs('[name="wb_result_note[]"]', merged.map((row) => {
      const parts = [];
      if (row.target && Number.isFinite(Number(row.target.background))) {
        parts.push(`目标图BG ${formatDecimal(row.target.background, 1)}`);
      }
      if (row.reference && Number.isFinite(Number(row.reference.background))) {
        parts.push(`内参图BG ${formatDecimal(row.reference.background, 1)}`);
      }
      if (row.normalized > 0) {
        parts.push('已计算 Target/Reference');
      }
      return parts.join('；');
    }));

    return completeRows.length;
  };

  const getExistingWbResultRowCount = () => {
    const labels = qsa('[name="wb_result_sample_label[]"]');
    const targets = qsa('input[name="wb_result_target_signal[]"]');
    const references = qsa('input[name="wb_result_reference_signal[]"]');
    const normalized = qsa('input[name="wb_result_normalized_signal[]"]');
    const count = Math.max(labels.length, targets.length, references.length, normalized.length);
    let filled = 0;
    for (let index = 0; index < count; index += 1) {
      const hasValue = [labels[index], targets[index], references[index], normalized[index]].some((input) => String(input?.value || '').trim() !== '');
      if (hasValue) {
        filled += 1;
      }
    }
    return filled;
  };

  const syncWbLaneCountFromExistingResults = () => {
    const laneInput = byId('wb-analysis-lanes');
    if (!laneInput) {
      return;
    }
    const current = parseInt(laneInput.value || '4', 10) || 4;
    const existingRows = getExistingWbResultRowCount();
    if (existingRows > current && current === 4) {
      laneInput.value = String(clamp(existingRows, 1, 24));
    }
  };

  const collectWbBandMetrics = (wbState, laneBoxes, centerRatio) => {
    const { image, grayscale, bandHeight } = wbState;
    const roi = wbState.roi || { x: 0, y: 0, w: 1, h: 1 };
    const roiX = roi.x * image.width;
    const roiY = roi.y * image.height;
    const roiW = roi.w * image.width;
    const roiH = roi.h * image.height;
    const halfHeight = Math.max(2, Math.round((bandHeight * roiH) / 2));
    const roiTop = Math.floor(roiY);
    const roiBottom = Math.floor(roiY + roiH) - 1;
    const readPixels = (xMin, xMax, yMin, yMax) => {
      if (xMax <= xMin || yMax < yMin) {
        return [];
      }
      const pixels = [];
      const x0 = clamp(xMin, 0, image.width - 1);
      const x1 = clamp(xMax, x0 + 1, image.width);
      const y0 = clamp(yMin, 0, image.height - 1);
      const y1 = clamp(yMax, 0, image.height - 1);
      if (y1 < y0) {
        return [];
      }
      for (let y = y0; y <= y1; y += 1) {
        for (let x = x0; x < x1; x += 1) {
          pixels.push(grayscale[(y * image.width) + x] || 0);
        }
      }
      return pixels;
    };
    return laneBoxes.map((box) => {
      const xStart = clamp(Math.floor(roiX + (box.x * roiW)), 0, image.width - 1);
      const xEnd = clamp(Math.ceil(roiX + ((box.x + box.w) * roiW)), xStart + 1, image.width);
      const centerY = Math.round(roiY + (centerRatio * roiH));
      const bandTop = clamp(centerY - halfHeight, roiTop, roiBottom);
      const bandBottom = clamp(centerY + halfHeight, roiTop, roiBottom);
      const bgGap = Math.max(1, Math.round(halfHeight * 0.45));
      const bgHeight = Math.max(2, Math.round(halfHeight * 0.9));
      const upperBgTop = clamp(bandTop - bgGap - bgHeight, roiTop, roiBottom);
      const upperBgBottom = clamp(bandTop - bgGap, roiTop, roiBottom);
      const lowerBgTop = clamp(bandBottom + bgGap, roiTop, roiBottom);
      const lowerBgBottom = clamp(bandBottom + bgGap + bgHeight, roiTop, roiBottom);
      const bandPixels = readPixels(xStart, xEnd, bandTop, bandBottom);
      const backgroundPixels = [
        ...readPixels(xStart, xEnd, upperBgTop, Math.min(upperBgBottom, bandTop - 1)),
        ...readPixels(xStart, xEnd, Math.max(lowerBgTop, bandBottom + 1), lowerBgBottom),
      ];
      const background = backgroundPixels.length ? median(backgroundPixels) : median(bandPixels);
      const rawTotal = bandPixels.reduce((sum, value) => sum + value, 0);
      const netTotal = bandPixels.reduce((sum, value) => sum + Math.max(value - background, 0), 0);
      const area = Math.max(bandPixels.length, 1);
      return {
        integrated: netTotal,
        mean: netTotal / area,
        rawMean: rawTotal / area,
        background,
        area,
      };
    });
  };

  const renderWbProfile = () => {
    const canvas = byId('smartlab-wb-profile');
    const empty = byId('smartlab-wb-profile-empty');
    if (!canvas || !empty || !state.wb?.profiles) {
      if (canvas) canvas.classList.add('d-none');
      if (empty) empty.classList.remove('d-none');
      return;
    }
    const { vertical } = state.wb.profiles;
    const width = Math.max(vertical.length, 240);
    const height = 160;
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, width, height);
    ctx.strokeStyle = '#cbd5e1';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, height - 20);
    ctx.lineTo(width, height - 20);
    ctx.stroke();
    ctx.strokeStyle = '#0f6fff';
    ctx.lineWidth = 2;
    ctx.beginPath();
    vertical.forEach((value, index) => {
      const x = (index / Math.max(vertical.length - 1, 1)) * width;
      const y = (height - 20) - (value * (height - 34));
      if (index === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    });
    ctx.stroke();
    ctx.strokeStyle = 'rgba(18, 74, 115, 0.9)';
    ctx.fillStyle = 'rgba(15, 111, 255, 0.08)';
    getWbLaneBoxes().forEach((box) => {
      const x = box.x * width;
      const w = Math.max(2, box.w * width);
      ctx.fillRect(x, 8, w, height - 20);
      ctx.strokeRect(x, 8, w, height - 20);
    });
    canvas.classList.remove('d-none');
    empty.classList.add('d-none');
  };

  const confidenceLabel = (score) => {
    if (score >= 0.75) return '高';
    if (score >= 0.45) return '中';
    return '低';
  };

  const autoSuggestWb = () => {
    if (!state.wb) {
      setStatus('smartlab-wb-analysis-status', '请先导入 WB 图像。', false);
      return;
    }
    const laneCount = clamp(parseInt(byId('wb-analysis-lanes')?.value || '4', 10) || 4, 1, 24);
    const profiles = computeWbProfiles();
    if (!profiles) {
      return;
    }
    const combinedMode = isWbCombinedBandMode();
    const activeBand = getWbActiveBand();
    const bandPeaks = findProfilePeaks(profiles.horizontal, combinedMode ? 2 : 1, Math.max(4, Math.round(profiles.horizontal.length * 0.18)));
    const fallbackBand = activeBand === 'reference' ? state.wb.referenceY : state.wb.targetY;
    const candidateBandCenters = bandPeaks.length
      ? bandPeaks.slice(0, combinedMode ? 2 : 1).map((peak) => peak.index / Math.max(profiles.horizontal.length - 1, 1))
      : (combinedMode ? [state.wb.targetY, state.wb.referenceY] : [fallbackBand]);
    const bandLaneProfile = computeWbLaneProfileFromBands(candidateBandCenters);
    const laneProfile = bandLaneProfile || profiles.vertical;
    const laneMarks = detectWbLaneMarks(laneProfile);
    const laneBoxResult = buildWbLaneBoxesFromMarks(laneMarks, laneCount);
    const laneBoxes = laneBoxResult.boxes;
    const laneDividers = boxesToLaneDividers(laneBoxes);
    profiles.vertical = laneProfile;
    const signalMedian = median(laneProfile);
    const visibleSignals = selectRegularLaneMarks(laneMarks, laneCount).map((mark) => mark.value);
    const laneContrast = visibleSignals.length ? median(visibleSignals) - signalMedian : 0;
    const bandContrast = bandPeaks.length ? median(bandPeaks.map((peak) => peak.value)) - median(profiles.horizontal) : 0;
    const laneConfidence = clamp((laneBoxResult.visibleCount / laneCount) * laneContrast * 1.7, 0, 1);
    const bandConfidence = clamp((bandPeaks.length / (combinedMode ? 2 : 1)) * bandContrast * 1.7, 0, 1);
    state.wb = {
      ...state.wb,
      laneDividers,
      laneBoxes,
      profiles,
      confidence: { lane: laneConfidence, band: bandConfidence },
      hasAnalysis: false,
    };
    if (combinedMode && bandPeaks.length >= 2) {
      const sortedBands = bandPeaks.slice(0, 2).sort((a, b) => a.index - b.index);
      state.wb.targetY = clamp(sortedBands[0].index / Math.max(profiles.horizontal.length - 1, 1), 0.05, 0.95);
      state.wb.referenceY = clamp(sortedBands[1].index / Math.max(profiles.horizontal.length - 1, 1), 0.05, 0.95);
      if (byId('wb-analysis-target-band')) byId('wb-analysis-target-band').value = String(Math.round(state.wb.targetY * 100));
      if (byId('wb-analysis-reference-band')) byId('wb-analysis-reference-band').value = String(Math.round(state.wb.referenceY * 100));
    } else if (!combinedMode && bandPeaks.length >= 1) {
      const center = clamp(bandPeaks[0].index / Math.max(profiles.horizontal.length - 1, 1), 0.05, 0.95);
      if (activeBand === 'reference') {
        state.wb.referenceY = center;
        if (byId('wb-analysis-reference-band')) byId('wb-analysis-reference-band').value = String(Math.round(center * 100));
      } else {
        state.wb.targetY = center;
        if (byId('wb-analysis-target-band')) byId('wb-analysis-target-band').value = String(Math.round(center * 100));
      }
    }
    rerenderWbPreview(false);
    const bandText = combinedMode ? 'target/内参双 band' : (activeBand === 'reference' ? '内参单 band' : '目标单 band');
    setStatus('smartlab-wb-analysis-status', `已按 Lane 数 ${laneCount} 生成 lane 框，并识别 ${bandText}：识别到 ${laneBoxResult.visibleCount} 个可见印记，其余按间距补齐。泳道置信度${confidenceLabel(laneConfidence)}，条带置信度${confidenceLabel(bandConfidence)}。`, laneConfidence >= 0.45 && bandConfidence >= 0.45);
  };

  const syncWbRecognitionEvidence = (sourceName, analyzed = false) => {
    const rawInput = byId('wb-raw-image-ref');
    const noteInput = byId('wb-annotation-note');
    const qcInput = byId('wb-qc-notes');
    if (rawInput && sourceName) {
      rawInput.value = sourceName;
    }
    if (noteInput && analyzed) {
      noteInput.value = 'Lane/ROI 已确认；真实图像定量';
    }
    if (qcInput && analyzed) {
      const methodNote = '局部背景扣除；integrated density';
      qcInput.value = qcInput.value
        ? (qcInput.value.includes(methodNote) ? qcInput.value : `${qcInput.value}；${methodNote}`)
        : `质控通过；${methodNote}`;
    }
    [rawInput, noteInput, qcInput].filter(Boolean).forEach((input) => {
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  };

  const analyzeWb = async (file) => {
    syncWbLaneCountFromExistingResults();
    const laneCount = clamp(parseInt(byId('wb-analysis-lanes')?.value || '4', 10) || 4, 1, 24);
    const targetY = clamp((parseFloat(byId('wb-analysis-target-band')?.value || '35') || 35) / 100, 0.05, 0.95);
    const referenceY = clamp((parseFloat(byId('wb-analysis-reference-band')?.value || '75') || 75) / 100, 0.05, 0.95);
    const bandHeight = clamp((parseFloat(byId('wb-analysis-band-window')?.value || '8') || 8) / 100, 0.02, 0.25);
    const invert = !!byId('wb-analysis-invert')?.checked;
    const { image, pixels } = await readImage(file);
    state.wb = makeWbImageState(image, pixels, file.name, {
      laneCount,
      targetY,
      referenceY,
      bandHeight,
      invert,
    });
    state.wb = {
      ...state.wb,
      image,
      sourceName: file.name,
    };
    state.wb.profiles = computeWbProfiles();
    syncWbRecognitionEvidence(file.name, false);
    rerenderWbPreview(false);
    setStatus('smartlab-wb-analysis-status', '图像已导入。请在图上拖框选择 WB 条带区域，然后点击“分析选区”。', true);
  };

  const importWbUploadToState = async (uploadId, uploadName) => {
    const experimentId = getExperimentId();
    if (!experimentId || !uploadId) {
      throw new Error('无法读取实验附件。');
    }
    const response = await fetch(`/api/v2/experiments/${experimentId}/uploads/${uploadId}?format=binary`, {
      credentials: 'same-origin',
      headers: {
        Accept: '*/*',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    if (!response.ok) {
      throw new Error(`读取实验附件失败 (${response.status})`);
    }
    const blob = await response.blob();
    const file = new File([blob], uploadName || `Upload ${uploadId}`, { type: blob.type || 'application/octet-stream' });
    await analyzeWb(file);
    if (state.wb) {
      state.wb.uploadId = Number(uploadId);
    }
  };

  const getSelectedWbUpload = () => {
    const select = byId('wb-analysis-upload-select');
    if (!select) {
      return null;
    }
    if (!select.value) {
      const firstImage = Array.from(select.options).find((option) => Number(option.value || 0) > 0);
      if (firstImage) {
        select.value = firstImage.value;
      }
    }
    const uploadId = parseInt(select.value || '0', 10) || 0;
    if (!uploadId) {
      return null;
    }
    const uploadName = select.selectedOptions?.[0]?.dataset?.uploadName || select.selectedOptions?.[0]?.textContent || `Upload ${uploadId}`;
    return { uploadId, uploadName };
  };

  const getSelectedUploadFrom = (selectId) => {
    const select = byId(selectId);
    if (!select) {
      return null;
    }
    const uploadId = parseInt(select.value || '0', 10) || 0;
    if (!uploadId) {
      return null;
    }
    const uploadName = select.selectedOptions?.[0]?.dataset?.uploadName || select.selectedOptions?.[0]?.textContent || `Upload ${uploadId}`;
    return { uploadId, uploadName };
  };

  const guessWbTargetName = (uploadName) => {
    const cleaned = String(uploadName || '')
      .replace(/\.(bmp|jpg|jpeg|png|webp)$/i, '')
      .replace(/[_-]+/g, ' ')
      .trim();
    const referenceMatch = cleaned.match(/\b(gapdh|actb|beta actin|β actin|tubulin|h3)\b/i);
    if (referenceMatch) {
      return referenceMatch[1].toUpperCase();
    }
    const marker = cleaned.match(/\b([A-Za-z][A-Za-z0-9-]{2,12})\b/);
    return marker ? marker[1] : 'Target';
  };

  const renderWbPairTargets = () => {
    const tbody = byId('wb-pair-target-list');
    if (!tbody) {
      return;
    }
    tbody.replaceChildren();
    if (!state.wbPairs.length) {
      const row = document.createElement('tr');
      row.innerHTML = '<td colspan="4" class="smallgray">还没有添加目标蛋白图。</td>';
      tbody.appendChild(row);
      return;
    }
    state.wbPairs.forEach((pair, index) => {
      const row = document.createElement('tr');
      const targetCell = document.createElement('td');
      const targetUploadCell = document.createElement('td');
      const referenceUploadCell = document.createElement('td');
      const actionCell = document.createElement('td');
      const removeButton = document.createElement('button');
      targetCell.textContent = pair.targetName;
      targetUploadCell.textContent = pair.targetUploadName;
      referenceUploadCell.textContent = pair.referenceUploadName;
      removeButton.className = 'btn btn-outline-danger btn-sm';
      removeButton.type = 'button';
      removeButton.dataset.wbPairRemove = String(index);
      removeButton.textContent = '移除';
      actionCell.appendChild(removeButton);
      row.append(targetCell, targetUploadCell, referenceUploadCell, actionCell);
      tbody.appendChild(row);
    });
    tbody.querySelectorAll('[data-wb-pair-remove]').forEach((button) => {
      button.addEventListener('click', () => {
        const index = parseInt(button.dataset.wbPairRemove || '-1', 10);
        if (index >= 0) {
          state.wbPairs.splice(index, 1);
          renderWbPairTargets();
        }
      });
    });
  };

  const addSelectedWbPairTarget = () => {
    const target = getSelectedUploadFrom('wb-pair-target-upload') || state.wbPairDraft.target;
    const reference = getSelectedUploadFrom('wb-pair-reference-upload') || state.wbPairDraft.reference;
    if (!target || !reference) {
      setStatus('smartlab-wb-pair-status', '请同时选择目标蛋白图和 GAPDH/内参图。', false);
      return;
    }
    const nameInput = byId('wb-pair-target-name');
    const targetName = (nameInput?.value || '').trim() || guessWbTargetName(target.uploadName);
    state.wbPairs.push({
      targetName,
      targetUploadId: target.uploadId,
      targetUploadName: target.uploadName,
      referenceUploadId: reference.uploadId,
      referenceUploadName: reference.uploadName,
    });
    if (nameInput) {
      nameInput.value = '';
    }
    renderWbPairTargets();
    setStatus('smartlab-wb-pair-status', `已添加 ${targetName}，可继续添加其他目标蛋白图。`, true);
  };

  const rememberSelectedWbPairUpload = (role, selected) => {
    if (!selected) {
      return;
    }
    state.wbPairDraft = {
      ...(state.wbPairDraft || {}),
      [role]: selected,
    };
  };

  const syncWbBandModeForPairSelect = (mode) => {
    const select = byId('wb-analysis-band-mode');
    if (!select) {
      return;
    }
    select.value = mode;
    select.dispatchEvent(new Event('change', { bubbles: true }));
  };

  const previewSelectedWbPairUpload = async (selectId, mode, label) => {
    const selected = getSelectedUploadFrom(selectId);
    if (!selected) {
      return;
    }
    rememberSelectedWbPairUpload(mode === 'pair-reference' ? 'reference' : 'target', selected);
    const mainSelect = byId('wb-analysis-upload-select');
    if (mainSelect) {
      mainSelect.value = String(selected.uploadId);
    }
    syncWbBandModeForPairSelect(mode);
    setStatus('smartlab-wb-analysis-status', `正在载入${label}：${selected.uploadName}...`, null);
    await importWbUploadToState(selected.uploadId, selected.uploadName);
    setStatus('smartlab-wb-analysis-status', `已载入${label}：${selected.uploadName}。当前为${mode === 'pair-reference' ? 'GAPDH/内参图单 band' : '目标蛋白图单 band'}，可点击“自动建议”。`, true);
  };

  const buildWbQuantStateFromUpload = async (uploadId, uploadName, bandCenter) => {
    const experimentId = getExperimentId();
    if (!experimentId || !uploadId) {
      throw new Error('无法读取实验附件。');
    }
    const response = await fetch(`/api/v2/experiments/${experimentId}/uploads/${uploadId}?format=binary`, {
      credentials: 'same-origin',
      headers: {
        Accept: '*/*',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    if (!response.ok) {
      throw new Error(`读取实验附件失败 (${response.status})`);
    }
    const blob = await response.blob();
    const file = new File([blob], uploadName || `Upload ${uploadId}`, { type: blob.type || 'application/octet-stream' });
    const { image, pixels } = await readImage(file);
    const laneCount = clamp(parseInt(byId('wb-analysis-lanes')?.value || '4', 10) || 4, 1, 24);
    const bandHeight = clamp((parseFloat(byId('wb-analysis-band-window')?.value || '8') || 8) / 100, 0.02, 0.25);
    const invert = !!byId('wb-analysis-invert')?.checked;
    return makeWbImageState(image, pixels, uploadName || file.name, {
      laneCount,
      targetY: bandCenter,
      referenceY: bandCenter,
      bandHeight,
      invert,
    });
  };

  const runWbPairQuant = async () => {
    if (!state.wbPairs.length) {
      setStatus('smartlab-wb-pair-status', '请先添加至少一个目标蛋白图。', false);
      return;
    }
    const laneCount = clamp(parseInt(byId('wb-analysis-lanes')?.value || '4', 10) || 4, 1, 24);
    const targetBand = clamp((parseFloat(byId('wb-analysis-target-band')?.value || '35') || 35) / 100, 0.05, 0.95);
    const referenceBand = clamp((parseFloat(byId('wb-analysis-reference-band')?.value || '75') || 75) / 100, 0.05, 0.95);
    setStatus('smartlab-wb-pair-status', `正在配对分析 ${state.wbPairs.length} 个目标蛋白图...`, null);
    const rows = [];
    for (const pair of state.wbPairs) {
      const targetStateRaw = await buildWbQuantStateFromUpload(pair.targetUploadId, pair.targetUploadName, targetBand);
      const referenceStateRaw = await buildWbQuantStateFromUpload(pair.referenceUploadId, pair.referenceUploadName, referenceBand);
      const targetState = autoDetectWbImageState(targetStateRaw, laneCount, targetBand);
      const referenceState = autoDetectWbImageState(referenceStateRaw, laneCount, referenceBand);
      const targetBoxes = normalizeWbLaneBoxes(targetState.laneBoxes || []);
      const referenceBoxes = normalizeWbLaneBoxes(referenceState.laneBoxes || []);
      const targetMetrics = collectWbBandMetrics(targetState, targetBoxes, targetBand);
      const referenceMetrics = collectWbBandMetrics(referenceState, referenceBoxes, referenceBand);
      for (let lane = 0; lane < laneCount; lane += 1) {
        const target = targetMetrics[lane] || { integrated: 0, background: 0 };
        const ref = referenceMetrics[lane] || { integrated: 0, background: 0 };
        const normalized = ref.integrated > 0 ? target.integrated / ref.integrated : 0;
        rows.push({
          label: `${pair.targetName} Lane ${lane + 1}`,
          target,
          ref,
          normalized,
          source: pair,
        });
      }
    }
    const controlValue = rows.find((row) => row.label.endsWith('Lane 1'))?.normalized || rows[0]?.normalized || 0;
    fillInputs('[name="wb_result_sample_label[]"]', rows.map((row) => row.label));
    fillInputs('input[name="wb_result_target_signal[]"]', rows.map((row) => formatDecimal(row.target.integrated, 1)));
    fillInputs('input[name="wb_result_reference_signal[]"]', rows.map((row) => formatDecimal(row.ref.integrated, 1)));
    fillInputs('input[name="wb_result_normalized_signal[]"]', rows.map((row) => formatDecimal(row.normalized, 3)));
    fillInputs('[name="wb_result_trend[]"]', rows.map((row) => {
      if (!controlValue) return '';
      const ratio = row.normalized / controlValue;
      return ratio > 1.15 ? 'up' : ratio < 0.85 ? 'down' : 'stable';
    }));
    fillInputs('[name="wb_result_note[]"]', rows.map((row) => {
      const targetBg = formatDecimal(row.target.background, 1);
      const refBg = formatDecimal(row.ref.background, 1);
      return `多图配对定量；${row.source.targetUploadName} / ${row.source.referenceUploadName}；BG ${targetBg}/${refBg}`;
    }));
    syncWbRecognitionEvidence(state.wbPairs.map((pair) => `${pair.targetUploadName}+${pair.referenceUploadName}`).join('; '), true);
    rerenderWb();
    setStatus('smartlab-wb-analysis-status', `已完成 ${state.wbPairs.length} 个目标蛋白图与 GAPDH/内参图的多图配对定量，共回填 ${rows.length} 行。`, true);
    setStatus('smartlab-wb-pair-status', `已按 lane 对齐完成 ${rows.length} 行 Target/Reference 归一化。`, true);
  };

  const autoImportWbUpload = async () => {
    if (state.wb || !byId('smartlab-wb-image-analysis')) {
      return;
    }
    const step = new URLSearchParams(window.location.search).get('smartlab_step') || '';
    if (step !== 'recognize' && window.location.hash !== '#smartlab-wb-image-analysis') {
      return;
    }
    const selected = getSelectedWbUpload();
    if (!selected) {
      setStatus('smartlab-wb-analysis-status', '未找到可直接分析的已上传图片；请先上传 PNG/JPG/WEBP/BMP 格式 WB 原图。', false);
      return;
    }
    try {
      setStatus('smartlab-wb-analysis-status', `正在载入已上传原始数据：${selected.uploadName}...`, null);
      await importWbUploadToState(selected.uploadId, selected.uploadName);
      setStatus('smartlab-wb-analysis-status', `已载入已上传原始数据：${selected.uploadName}。请拖框选择 WB 区域，或点击“自动建议”。`, true);
    } catch (error) {
      setStatus('smartlab-wb-analysis-status', error.message || '载入已上传 WB 图片失败。', false);
    }
  };

  const rerenderWbPreview = (commit = false) => {
    if (!state.wb) {
      return;
    }
    const canvas = byId('smartlab-wb-preview');
    const empty = byId('smartlab-wb-preview-empty');
    const meta = byId('smartlab-wb-preview-meta');
    const controlSelect = byId('wb-analysis-control-lane');
    const { image, grayscale, targetY, referenceY, bandHeight } = state.wb;
    const combinedMode = isWbCombinedBandMode();
    const activeBand = getWbActiveBand();
    const roi = getWbRoi();
    const laneBoxes = getWbLaneBoxes();
    state.wb.controlLane = clamp(state.wb.controlLane || 0, 0, Math.max(laneBoxes.length - 1, 0));
    const ctx = canvas.getContext('2d');
    canvas.width = image.width;
    canvas.height = image.height;
    ctx.drawImage(image, 0, 0);
    const roiX = roi.x * image.width;
    const roiY = roi.y * image.height;
    const roiW = roi.w * image.width;
    const roiH = roi.h * image.height;
    state.wb.profiles = computeWbProfiles();
    if (state.wb.hasUserRoi) {
      ctx.save();
      ctx.fillStyle = 'rgba(15, 23, 42, 0.22)';
      ctx.fillRect(0, 0, image.width, roiY);
      ctx.fillRect(0, roiY + roiH, image.width, image.height - (roiY + roiH));
      ctx.fillRect(0, roiY, roiX, roiH);
      ctx.fillRect(roiX + roiW, roiY, image.width - (roiX + roiW), roiH);
      ctx.strokeStyle = 'rgba(15, 111, 255, 0.95)';
      ctx.lineWidth = Math.max(2, image.width / 260);
      ctx.setLineDash([Math.max(5, image.width / 140), Math.max(4, image.width / 180)]);
      ctx.strokeRect(roiX, roiY, roiW, roiH);
      ctx.restore();
    }
    ctx.save();
    ctx.lineWidth = Math.max(1.5, image.width / 320);
    ctx.font = `${Math.max(12, Math.round(image.width / 58))}px sans-serif`;
    ctx.textBaseline = 'top';
    laneBoxes.forEach((box, index) => {
      const laneX = roiX + (box.x * roiW);
      const laneW = Math.max(1, box.w * roiW);
      ctx.fillStyle = box.inferred ? 'rgba(15, 111, 255, 0.07)' : 'rgba(15, 111, 255, 0.13)';
      ctx.strokeStyle = box.inferred ? 'rgba(18, 74, 115, 0.68)' : 'rgba(18, 74, 115, 0.92)';
      if (box.inferred) {
        ctx.setLineDash([Math.max(5, image.width / 130), Math.max(4, image.width / 170)]);
      } else {
        ctx.setLineDash([]);
      }
      ctx.fillRect(laneX, roiY, laneW, roiH);
      ctx.strokeRect(laneX, roiY, laneW, roiH);
      ctx.setLineDash([]);
      const label = `Lane ${index + 1}`;
      const labelWidth = ctx.measureText(label).width + 8;
      const labelX = laneX + Math.min(6, Math.max(2, laneW * 0.08));
      const labelY = roiY + Math.min(6, Math.max(2, roiH * 0.03));
      ctx.fillStyle = 'rgba(255, 255, 255, 0.78)';
      ctx.fillRect(labelX - 3, labelY - 2, Math.min(labelWidth, laneW - 4), Math.max(15, image.width / 50));
      ctx.fillStyle = 'rgba(18, 74, 115, 0.96)';
      ctx.fillText(label, labelX, labelY, Math.max(20, laneW - 8));
    });
    ctx.restore();
    const drawBand = (centerRatio, color) => {
      const center = roiY + (centerRatio * roiH);
      const half = (bandHeight * roiH) / 2;
      ctx.fillStyle = color.replace('0.95', '0.14');
      ctx.strokeStyle = color;
      ctx.fillRect(roiX, center - half, roiW, half * 2);
      ctx.strokeRect(roiX, center - half, roiW, half * 2);
    };
    if (combinedMode || activeBand === 'target') {
      drawBand(targetY, 'rgba(215, 73, 47, 0.95)');
    }
    if (combinedMode || activeBand === 'reference') {
      drawBand(referenceY, 'rgba(37, 142, 95, 0.95)');
    }
    canvas.classList.remove('d-none');
    if (empty) empty.classList.add('d-none');
    if (meta) {
      const roiText = state.wb.hasUserRoi
        ? `选区 ${Math.round(roiW)}×${Math.round(roiH)}px @ ${Math.round(roiX)},${Math.round(roiY)}`
        : '未选择区域，默认使用整张图';
      const confidenceText = state.wb.confidence
        ? ` 泳道置信度${confidenceLabel(state.wb.confidence.lane)}，条带置信度${confidenceLabel(state.wb.confidence.band)}。`
        : '';
      const modeText = combinedMode ? '双 band：target + 内参' : (activeBand === 'reference' ? '单 band：GAPDH/内参图' : '单 band：目标蛋白图');
      meta.textContent = `${image.width}×${image.height}px，${laneBoxes.length} 个 lane，${modeText}，${roiText}。${confidenceText}`;
    }
    renderWbProfile();
    if (controlSelect) {
      controlSelect.replaceChildren();
      Array.from({ length: laneBoxes.length }, (_, index) => index).forEach((index) => {
        const option = document.createElement('option');
        option.value = String(index);
        option.textContent = `Lane ${index + 1}`;
        if (index === state.wb.controlLane) option.selected = true;
        controlSelect.appendChild(option);
      });
    }
    if (!commit && !state.wb.hasAnalysis) {
      return;
    }
    state.wb.hasAnalysis = true;
    if (!combinedMode) {
      const bandCenter = activeBand === 'reference' ? referenceY : targetY;
      const bandMetrics = collectWbBandMetrics(state.wb, laneBoxes, bandCenter);
      const rows = laneBoxes.map((_, lane) => {
        const signal = bandMetrics[lane];
        return { label: `Lane ${lane + 1}`, signal };
      });
      const completeRows = mergeWbSingleBandRows(activeBand, rows, state.wb.sourceName);
      syncWbRecognitionEvidence(state.wb.sourceName, true);
      rerenderWb();
      const pairStatus = completeRows > 0
        ? `已合并目标/内参两路信号，完成 ${completeRows} 行 Target/Reference 归一化。`
        : '已保留本路信号，等待另一张图分析后自动计算 Target/Reference。';
      setStatus('smartlab-wb-analysis-status', `已完成${activeBand === 'reference' ? '内参图' : '目标蛋白图'}单 band 定量：${rows.length} 个 lane。${pairStatus}`, true);
      return;
    }
    const targetMetrics = collectWbBandMetrics(state.wb, laneBoxes, targetY);
    const referenceMetrics = collectWbBandMetrics(state.wb, laneBoxes, referenceY);
    const rows = laneBoxes.map((_, lane) => {
      const target = targetMetrics[lane];
      const ref = referenceMetrics[lane];
      const normalized = ref.integrated > 0 ? target.integrated / ref.integrated : 0;
      return { label: `Lane ${lane + 1}`, target, ref, normalized };
    });
    const controlValue = rows[state.wb.controlLane]?.normalized || 0;
    fillInputs('[name="wb_result_sample_label[]"]', rows.map((row) => row.label));
    fillInputs('input[name="wb_result_target_signal[]"]', rows.map((row) => formatDecimal(row.target.integrated, 1)));
    fillInputs('input[name="wb_result_reference_signal[]"]', rows.map((row) => formatDecimal(row.ref.integrated, 1)));
    fillInputs('input[name="wb_result_normalized_signal[]"]', rows.map((row) => formatDecimal(row.normalized, 3)));
    fillInputs('[name="wb_result_trend[]"]', rows.map((row, index) => {
      if (index === state.wb.controlLane) return 'stable';
      if (!controlValue) return '';
      const ratio = row.normalized / controlValue;
      return ratio > 1.15 ? 'up' : ratio < 0.85 ? 'down' : 'stable';
    }));
    fillInputs('[name="wb_result_note[]"]', rows.map((row) => {
      const targetBg = formatDecimal(row.target.background, 1);
      const refBg = formatDecimal(row.ref.background, 1);
      return `真实图像定量；BG ${targetBg}/${refBg}`;
    }));
    syncWbRecognitionEvidence(state.wb.sourceName, true);
    rerenderWb();
    setStatus('smartlab-wb-analysis-status', `已完成真实 WB 图像定量：${rows.length} 个 lane，按局部背景扣除计算 integrated density，并回填 Target/Reference/Normalized。`, true);
  };

  const bindWbSelectionCanvas = () => {
    const canvas = byId('smartlab-wb-preview');
    if (!canvas) return;
    const getPoint = (event) => {
      const rect = canvas.getBoundingClientRect();
      return {
        x: clamp((event.clientX - rect.left) / rect.width, 0, 1),
        y: clamp((event.clientY - rect.top) / rect.height, 0, 1),
      };
    };
    const resolveMode = (event) => {
      if (!state.wb) return null;
      const { x, y } = getPoint(event);
      const roi = getWbRoi();
      const handle = state.wb.hasUserRoi ? resolveRoiHandle(roi, x, y) : null;
      if (handle) return { mode: handle.mode };
      const inside = x >= roi.x && x <= roi.x + roi.w && y >= roi.y && y <= roi.y + roi.h;
      if (inside) {
        const hitTolerance = Math.max(0.025, ROI_HANDLE_RADIUS * 1.1);
        const bandHitTolerance = ((state.wb.bandHeight || 0.08) * roi.h / 2) + hitTolerance;
        const combinedMode = isWbCombinedBandMode();
        const activeBand = getWbActiveBand();
        const bandCandidates = [];
        if (combinedMode || activeBand === 'target') {
          bandCandidates.push({ mode: 'target-band', distance: Math.abs(y - (roi.y + (state.wb.targetY * roi.h))) });
        }
        if (combinedMode || activeBand === 'reference') {
          bandCandidates.push({ mode: 'reference-band', distance: Math.abs(y - (roi.y + (state.wb.referenceY * roi.h))) });
        }
        const bandHits = bandCandidates.map((hit) => ({ ...hit, score: hit.distance / bandHitTolerance }))
          .filter((hit) => hit.distance <= bandHitTolerance)
          .sort((a, b) => a.score - b.score);
        if (bandHits.length) return bandHits[0];
        if (state.wb.laneBoxes?.length) {
          const laneBoxes = getWbLaneBoxes();
          const laneEdgeHits = laneBoxes.flatMap((box, index) => {
            const left = roi.x + (box.x * roi.w);
            const right = roi.x + ((box.x + box.w) * roi.w);
            return [
              { mode: 'lane-box-left', laneIndex: index, distance: Math.abs(x - left) },
              { mode: 'lane-box-right', laneIndex: index, distance: Math.abs(x - right) },
            ];
          }).map((hit) => ({ ...hit, score: hit.distance / hitTolerance }))
            .filter((hit) => hit.distance <= hitTolerance)
            .sort((a, b) => a.score - b.score);
          if (laneEdgeHits.length) return laneEdgeHits[0];
          const laneBodyHits = laneBoxes.map((box, index) => {
            const left = roi.x + (box.x * roi.w);
            const right = roi.x + ((box.x + box.w) * roi.w);
            return { mode: 'lane-box-move', laneIndex: index, inside: x >= left && x <= right };
          }).filter((hit) => hit.inside);
          if (laneBodyHits.length) return laneBodyHits[0];
        }
        const laneHits = (state.wb.laneDividers || []).map((divider, index) => ({
          mode: 'lane-divider',
          laneIndex: index,
          distance: Math.abs(x - (roi.x + (divider * roi.w))),
          score: Math.abs(x - (roi.x + (divider * roi.w))) / hitTolerance,
        })).filter((hit) => hit.distance <= hitTolerance)
          .sort((a, b) => a.score - b.score);
        if (laneHits.length) return laneHits[0];
      }
      if (inside && state.wb.hasUserRoi) {
        return { mode: 'move' };
      }
      return { mode: 'draw' };
    };
    canvas.addEventListener('mousedown', (event) => {
      if (!state.wb) return;
      const point = getPoint(event);
      const hit = resolveMode(event) || { mode: 'draw' };
      state.drag = {
        kind: 'wb',
        mode: hit.mode,
        startX: point.x,
        startY: point.y,
        roi: cloneRoi(state.wb.roi || { x: 0, y: 0, w: 1, h: 1 }),
        laneDividers: state.wb.laneDividers.slice(),
        laneBoxes: getWbLaneBoxes().map((box) => ({ ...box })),
        laneIndex: hit.laneIndex,
        targetY: state.wb.targetY,
        referenceY: state.wb.referenceY,
      };
      if (hit.mode === 'draw') {
        state.wb.roi = clampRoiBox(point.x, point.y, point.x + MIN_ROI_SIZE, point.y + MIN_ROI_SIZE);
        state.wb.hasUserRoi = true;
        state.wb.hasAnalysis = false;
        state.wb.confidence = null;
        rerenderWbPreview(false);
      }
    });
    canvas.addEventListener('mousemove', (event) => {
      if (!state.wb) return;
      const hit = resolveMode(event);
      canvas.style.cursor = cursorForWbMode(state.drag?.kind === 'wb' ? state.drag.mode : hit?.mode);
      if (!state.drag || state.drag.kind !== 'wb') return;
      const point = getPoint(event);
      const dx = point.x - state.drag.startX;
      const dy = point.y - state.drag.startY;
      if (state.drag.mode === 'lane-divider') {
        state.wb.laneDividers = state.drag.laneDividers.slice();
        state.wb.laneDividers[state.drag.laneIndex] = clampWbLaneDivider(state.drag.laneIndex, state.drag.laneDividers[state.drag.laneIndex] + (dx / state.drag.roi.w));
        state.wb.laneBoxes = null;
      } else if (state.drag.mode === 'lane-box-left' || state.drag.mode === 'lane-box-right' || state.drag.mode === 'lane-box-move') {
        const laneBoxes = state.drag.laneBoxes.map((box) => ({ ...box }));
        const box = laneBoxes[state.drag.laneIndex];
        const dxInRoi = dx / Math.max(state.drag.roi.w, 0.001);
        const minWidth = 0.025;
        if (state.drag.mode === 'lane-box-move') {
          box.x = clamp(box.x + dxInRoi, 0, 1 - box.w);
        } else if (state.drag.mode === 'lane-box-left') {
          const right = box.x + box.w;
          const left = clamp(box.x + dxInRoi, 0, right - minWidth);
          box.x = left;
          box.w = right - left;
        } else {
          const right = clamp(box.x + box.w + dxInRoi, box.x + minWidth, 1);
          box.w = right - box.x;
        }
        box.inferred = false;
        state.wb.laneBoxes = normalizeWbLaneBoxes(laneBoxes);
        state.wb.laneDividers = boxesToLaneDividers(state.wb.laneBoxes);
      } else if (state.drag.mode === 'target-band') {
        state.wb.targetY = clamp(state.drag.targetY + (dy / state.drag.roi.h), 0.05, 0.95);
        updateWbBandInputs();
      } else if (state.drag.mode === 'reference-band') {
        state.wb.referenceY = clamp(state.drag.referenceY + (dy / state.drag.roi.h), 0.05, 0.95);
        updateWbBandInputs();
      } else if (state.drag.mode === 'move') {
        state.wb.roi = clampRoi({ x: state.drag.roi.x + dx, y: state.drag.roi.y + dy, w: state.drag.roi.w, h: state.drag.roi.h });
      } else if (state.drag.mode === 'draw') {
        state.wb.roi = clampRoiBox(state.drag.startX, state.drag.startY, point.x, point.y);
      } else {
        state.wb.roi = resizeRoiByMode(state.drag.roi, state.drag.mode, dx, dy);
      }
      if (!isWbManualLineMode(state.drag.mode)) {
        state.wb.hasUserRoi = true;
      }
      state.wb.hasAnalysis = false;
      state.wb.confidence = null;
      rerenderWbPreview(false);
    });
    ['mouseup', 'mouseleave'].forEach((eventName) => {
      canvas.addEventListener(eventName, () => {
        if (state.drag?.kind === 'wb') {
          const lineAdjusted = isWbManualLineMode(state.drag.mode);
          state.drag = null;
          setStatus('smartlab-wb-analysis-status', lineAdjusted ? '已手动调整 lane/band，点击“分析选区”重新生成结果。' : '已选择 WB 区域，点击“分析选区”生成结果。', true);
        }
        canvas.style.cursor = 'default';
      });
    });
  };

  const exportWb = () => {
    if (!state.wb) {
      return;
    }
    exportCanvas('smartlab-wb-preview', `${state.wb.sourceName.replace(/\.[^.]+$/, '') || 'wb'}-annotated.png`);
  };

  const isWbManualLineMode = (mode) => ['lane-divider', 'lane-box-left', 'lane-box-right', 'lane-box-move', 'target-band', 'reference-band'].includes(mode);

  const cursorForWbMode = (mode) => {
    if (mode === 'lane-divider' || mode === 'lane-box-left' || mode === 'lane-box-right') return 'col-resize';
    if (mode === 'lane-box-move') return 'move';
    if (mode === 'target-band' || mode === 'reference-band') return 'row-resize';
    return cursorForRoiMode(mode);
  };

  const updateWbBandInputs = () => {
    if (!state.wb) return;
    const targetInput = byId('wb-analysis-target-band');
    const referenceInput = byId('wb-analysis-reference-band');
    if (targetInput) targetInput.value = String(Math.round(state.wb.targetY * 100));
    if (referenceInput) referenceInput.value = String(Math.round(state.wb.referenceY * 100));
  };

  const clampWbLaneDivider = (index, value) => {
    const dividers = state.wb?.laneDividers || [];
    const minGap = 0.03;
    const previous = index === 0 ? 0 : dividers[index - 1];
    const next = index === dividers.length - 1 ? 1 : dividers[index + 1];
    return clamp(value, previous + minGap, next - minGap);
  };

  const buildIfAnnotationSummary = () => {
    if (!state.ifp) {
      return '';
    }
    const signalChannel = byId('if-analysis-signal-channel')?.value || 'green';
    const nuclearChannel = byId('if-analysis-nuclear-channel')?.value || 'blue';
    const threshold = byId('if-analysis-threshold')?.value || '160';
    const nuclearThreshold = byId('if-analysis-nuclear-threshold')?.value || '120';
    const roiCount = state.ifp.rois.length;
    return `IF analysis | file ${state.ifp.sourceName || 'local image'} | signal ${signalChannel} >= ${threshold} | nuclear ${nuclearChannel} >= ${nuclearThreshold} | ROI ${roiCount}`;
  };

  const buildIhcAnnotationSummary = () => {
    const current = getActiveIhcEntry();
    if (!current) {
      return '';
    }
    const mode = byId('ihc-analysis-mode')?.value || 'deconv';
    const threshold = byId('ihc-analysis-threshold')?.value || '32';
    const heThreshold = byId('ihc-analysis-he-threshold')?.value || '24';
    const roiCount = current.rois.length;
    return `IHC analysis | file ${current.sourceName || 'local image'} | mode ${mode} | DAB ${threshold} | H ${heThreshold} | ROI ${roiCount}`;
  };

  const applyAnnotationNote = (inputId, summary, statusId, label) => {
    const input = byId(inputId);
    if (!input || !summary) {
      setStatus(statusId, `${label} 还没有可写入的分析摘要。`, false);
      return;
    }
    input.value = summary;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    setStatus(statusId, `已把 ${label} 分析摘要写入图像标注。`, true);
  };

  const imageToDataUrl = (image) => {
    const canvas = document.createElement('canvas');
    canvas.width = image.width;
    canvas.height = image.height;
    const context = canvas.getContext('2d');
    context.drawImage(image, 0, 0);
    return canvas.toDataURL('image/png');
  };

  const ihcToDataUrl = (usePreview = true) => {
    const current = getActiveIhcEntry();
    if (!current) {
      return '';
    }
    const canvas = document.createElement('canvas');
    canvas.width = current.pixels.width;
    canvas.height = current.pixels.height;
    const context = canvas.getContext('2d');
    if (usePreview) {
      const imageData = context.createImageData(current.pixels.width, current.pixels.height);
      imageData.data.set(buildIhcPreviewRgba(current.pixels, getIhcOps()));
      context.putImageData(imageData, 0, 0);
    } else {
      const imageData = context.createImageData(current.pixels.width, current.pixels.height);
      imageData.data.set(current.pixels.rgba);
      context.putImageData(imageData, 0, 0);
    }
    return canvas.toDataURL('image/png');
  };

  const roiToPixels = (dimensions, roi) => ({
    label: roi.label,
    x: Math.round(roi.x * dimensions.width),
    y: Math.round(roi.y * dimensions.height),
    w: Math.round(roi.w * dimensions.width),
    h: Math.round(roi.h * dimensions.height),
  });

  const bindRoiCanvas = (kind, rerender) => {
    const canvas = byId(`smartlab-${kind}-preview`);
    if (!canvas) {
      return;
    }
    const getState = () => getCollectionState(kind);
    const getPoint = (event) => {
      const rect = canvas.getBoundingClientRect();
      return {
        x: (event.clientX - rect.left) / rect.width,
        y: (event.clientY - rect.top) / rect.height,
      };
    };
    const resolveMode = (event) => {
      const current = getState();
      if (!current) return null;
      const { x, y } = getPoint(event);
      for (let index = current.rois.length - 1; index >= 0; index -= 1) {
        const roi = current.rois[index];
        const handle = resolveRoiHandle(roi, x, y);
        if (handle) {
          current.activeIndex = index;
          syncRoiControls(kind);
          return { mode: handle.mode, index };
        }
        const inside = x >= roi.x && x <= roi.x + roi.w && y >= roi.y && y <= roi.y + roi.h;
        if (!inside) continue;
        current.activeIndex = index;
        syncRoiControls(kind);
        return { mode: 'move', index };
      }
      return { mode: 'draw', index: current.activeIndex };
    };
    canvas.addEventListener('mousedown', (event) => {
      const current = getState();
      const hit = resolveMode(event);
      if (!current || !hit) return;
      const point = getPoint(event);
      const roi = current.rois[hit.index] || getDefaultRoi();
      state.drag = {
        kind,
        mode: hit.mode,
        index: hit.index,
        startX: point.x,
        startY: point.y,
        roi: cloneRoi(roi),
      };
      if (hit.mode === 'draw') {
        current.rois[hit.index] = clampRoiBox(point.x, point.y, point.x + MIN_ROI_SIZE, point.y + MIN_ROI_SIZE);
        current.rois[hit.index].label = current.rois[hit.index].label || getCollectionLabel(kind, hit.index);
        rerender();
      }
    });
    canvas.addEventListener('mousemove', (event) => {
      const current = getState();
      if (!current) return;
      const hit = resolveMode(event);
      canvas.style.cursor = cursorForRoiMode(state.drag?.kind === kind ? state.drag.mode : hit?.mode);
      if (!state.drag || state.drag.kind !== kind) return;
      const { x, y } = getPoint(event);
      const dx = x - state.drag.startX;
      const dy = y - state.drag.startY;
      current.activeIndex = state.drag.index;
      if (state.drag.mode === 'move') {
        current.rois[state.drag.index] = clampRoi({ x: state.drag.roi.x + dx, y: state.drag.roi.y + dy, w: state.drag.roi.w, h: state.drag.roi.h });
      } else if (state.drag.mode === 'draw') {
        current.rois[state.drag.index] = clampRoiBox(state.drag.startX, state.drag.startY, x, y);
      } else {
        current.rois[state.drag.index] = resizeRoiByMode(state.drag.roi, state.drag.mode, dx, dy);
      }
      current.rois[state.drag.index].label = current.rois[state.drag.index].label || getCollectionLabel(kind, state.drag.index);
      rerender();
    });
    ['mouseleave', 'mouseup'].forEach((eventName) => {
      canvas.addEventListener(eventName, () => {
        if (state.drag?.kind === kind) {
          state.drag = null;
        }
        canvas.style.cursor = 'default';
      });
    });
    window.addEventListener('mouseup', () => {
      if (state.drag?.kind === kind) {
        state.drag = null;
        canvas.style.cursor = 'default';
      }
    });
  };

  document.addEventListener('DOMContentLoaded', function () {
    qsa('[name="wb_result_sample_label[]"], input[name="wb_result_normalized_signal[]"]').forEach((input) => {
      input.addEventListener('input', rerenderWb);
      input.addEventListener('change', rerenderWb);
    });
    qsa('input[name="if_result_field_label[]"], input[name="if_result_mean_intensity[]"]').forEach((input) => {
      input.addEventListener('input', () => refreshBarChart('input[name="if_result_field_label[]"]', 'input[name="if_result_mean_intensity[]"]', 'smartlab-if-chart', 'smartlab-if-chart-empty', 1));
      input.addEventListener('change', () => refreshBarChart('input[name="if_result_field_label[]"]', 'input[name="if_result_mean_intensity[]"]', 'smartlab-if-chart', 'smartlab-if-chart-empty', 1));
    });
    qsa('input[name="ihc_result_region_label[]"], input[name="ihc_result_h_score[]"]').forEach((input) => {
      input.addEventListener('input', () => refreshBarChart('input[name="ihc_result_region_label[]"]', 'input[name="ihc_result_h_score[]"]', 'smartlab-ihc-chart', 'smartlab-ihc-chart-empty', 1));
      input.addEventListener('change', () => refreshBarChart('input[name="ihc_result_region_label[]"]', 'input[name="ihc_result_h_score[]"]', 'smartlab-ihc-chart', 'smartlab-ihc-chart-empty', 1));
    });
    byId('wb-analysis-file')?.addEventListener('change', async (event) => {
      const input = event.currentTarget;
      const file = input.files?.[0];
      if (!file) return;
      try { await analyzeWb(file); } catch (error) { setStatus('smartlab-wb-analysis-status', error.message || 'WB 分析失败。', false); }
      input.value = '';
    });
    byId('wb-analysis-import-upload')?.addEventListener('click', async () => {
      const selected = getSelectedWbUpload();
      if (!selected) {
        setStatus('smartlab-wb-analysis-status', '请先选择一个已上传 WB 图片附件。', false);
        return;
      }
      try {
        setStatus('smartlab-wb-analysis-status', `正在载入已上传原始数据：${selected.uploadName}...`, null);
        await importWbUploadToState(selected.uploadId, selected.uploadName);
        setStatus('smartlab-wb-analysis-status', `已载入已上传原始数据：${selected.uploadName}。请拖框选择 WB 区域，或点击“自动建议”。`, true);
      } catch (error) {
        setStatus('smartlab-wb-analysis-status', error.message || '载入已上传 WB 图片失败。', false);
      }
    });
    byId('wb-pair-add-target')?.addEventListener('click', addSelectedWbPairTarget);
    byId('wb-pair-run')?.addEventListener('click', () => {
      runWbPairQuant().catch((error) => {
        setStatus('smartlab-wb-pair-status', error.message || '多图配对定量失败。', false);
      });
    });
    byId('wb-pair-target-upload')?.addEventListener('change', () => {
      previewSelectedWbPairUpload('wb-pair-target-upload', 'pair-target', '目标蛋白图').catch((error) => {
        setStatus('smartlab-wb-analysis-status', error.message || '载入目标蛋白图失败。', false);
      });
    });
    byId('wb-pair-reference-upload')?.addEventListener('change', () => {
      previewSelectedWbPairUpload('wb-pair-reference-upload', 'pair-reference', 'GAPDH/内参图').catch((error) => {
        setStatus('smartlab-wb-analysis-status', error.message || '载入 GAPDH/内参图失败。', false);
      });
    });
    renderWbPairTargets();
    byId('wb-analysis-control-lane')?.addEventListener('change', (event) => {
      if (!state.wb) return;
      state.wb.controlLane = parseInt(event.currentTarget.value || '0', 10) || 0;
      rerenderWbPreview(state.wb.hasAnalysis);
    });
    ['wb-analysis-lanes', 'wb-analysis-target-band', 'wb-analysis-reference-band', 'wb-analysis-band-window', 'wb-analysis-band-mode', 'wb-analysis-invert'].forEach((id) => {
      byId(id)?.addEventListener('change', () => {
        if (!state.wb) return;
        const laneCount = clamp(parseInt(byId('wb-analysis-lanes')?.value || '4', 10) || 4, 1, 24);
        state.wb = {
          ...state.wb,
          ...(id === 'wb-analysis-lanes' ? {
            laneDividers: Array.from({ length: laneCount - 1 }, (_, index) => (index + 1) / laneCount),
            laneBoxes: null,
            controlLane: 0,
          } : {}),
          targetY: clamp((parseFloat(byId('wb-analysis-target-band')?.value || '35') || 35) / 100, 0.05, 0.95),
          referenceY: clamp((parseFloat(byId('wb-analysis-reference-band')?.value || '75') || 75) / 100, 0.05, 0.95),
          bandHeight: clamp((parseFloat(byId('wb-analysis-band-window')?.value || '8') || 8) / 100, 0.02, 0.25),
          confidence: null,
        };
        rerenderWbPreview(state.wb.hasAnalysis);
      });
    });
    byId('wb-analysis-auto-detect')?.addEventListener('click', autoSuggestWb);
    byId('wb-analysis-run')?.addEventListener('click', () => {
      if (!state.wb) {
        setStatus('smartlab-wb-analysis-status', '请先导入 WB 图像。', false);
        return;
      }
      rerenderWbPreview(true);
    });
    document.addEventListener('smartlab:before-save-next', (event) => {
      if (event.detail?.step !== 'recognize' || !byId('smartlab-wb-image-analysis') || !state.wb) {
        return;
      }
      rerenderWbPreview(true);
    });
    byId('wb-analysis-reset-roi')?.addEventListener('click', () => {
      if (!state.wb) {
        setStatus('smartlab-wb-analysis-status', '请先导入 WB 图像。', false);
        return;
      }
      state.wb.roi = { x: 0, y: 0, w: 1, h: 1 };
      state.wb.hasUserRoi = false;
      state.wb.hasAnalysis = false;
      state.wb.confidence = null;
      rerenderWbPreview(false);
      setStatus('smartlab-wb-analysis-status', '已重置选区。可重新拖框，或直接分析整张图。', true);
    });
    byId('wb-analysis-export')?.addEventListener('click', exportWb);
    byId('if-analysis-file')?.addEventListener('change', async (event) => {
      const input = event.currentTarget;
      const file = input.files?.[0];
      if (!file) return;
      try {
        const loaded = await readImage(file);
        state.ifp = { image: loaded.image, pixels: loaded.pixels, rois: [{ ...getDefaultRoi(), label: 'Field 1' }], activeIndex: 0, sourceName: file.name };
        syncRoiControls('if');
        rerenderIf();
      } catch (error) {
        setStatus('smartlab-if-analysis-status', error.message || 'IF 分析失败。', false);
      }
      input.value = '';
    });
    ['if-analysis-threshold', 'if-analysis-nuclear-threshold', 'if-analysis-signal-channel', 'if-analysis-nuclear-channel', 'if-analysis-field-label'].forEach((id) => {
      byId(id)?.addEventListener('change', rerenderIf);
      byId(id)?.addEventListener('input', () => {
        if (id === 'if-analysis-field-label' && state.ifp) {
          state.ifp.rois[state.ifp.activeIndex].label = byId(id)?.value.trim() || getCollectionLabel('if', state.ifp.activeIndex);
          syncRoiControls('if');
        }
      });
    });
    byId('if-analysis-roi-select')?.addEventListener('change', (event) => {
      if (!state.ifp) return;
      state.ifp.activeIndex = clamp(parseInt(event.currentTarget.value || '0', 10) || 0, 0, state.ifp.rois.length - 1);
      syncRoiControls('if');
      rerenderIf();
    });
    byId('if-analysis-add-roi')?.addEventListener('click', () => addRoi('if', rerenderIf));
    byId('if-analysis-delete-roi')?.addEventListener('click', () => deleteRoi('if', rerenderIf));
    byId('ihc-analysis-file')?.addEventListener('change', async (event) => {
      const input = event.currentTarget;
      const files = Array.from(input.files || []);
      if (!files.length) return;
      try {
        const images = [];
        for (const file of files) {
          const loaded = await readImage(file);
          let uploadId = 0;
          try {
            uploadId = (await uploadIhcImageToEntity(file)) || 0;
          } catch (_error) {
            uploadId = 0;
          }
          images.push({ image: loaded.image, pixels: loaded.pixels, dataUrl: loaded.dataUrl, uploadId, rois: getIhcRoiTemplate(), activeIndex: 0, sourceName: file.name });
        }
        state.ihc = { images, activeImageIndex: 0, compareImageIndex: images.length > 1 ? 1 : -1 };
        await persistIhcImageCache();
        syncIhcImageControls();
        syncRoiControls('ihc');
        rerenderIhc();
      } catch (error) {
        setStatus('smartlab-ihc-analysis-status', error.message || 'IHC 分析失败。', false);
      }
      input.value = '';
    });
    byId('ihc-analysis-import-upload')?.addEventListener('click', async () => {
      const select = byId('ihc-analysis-upload-select');
      const uploadId = parseInt(select?.value || '0', 10) || 0;
      const uploadName = select?.selectedOptions?.[0]?.dataset?.uploadName || select?.selectedOptions?.[0]?.textContent || `Upload ${uploadId}`;
      if (!uploadId) {
        setStatus('smartlab-ihc-analysis-status', '请先选择一个当前实验附件。', false);
        return;
      }
      try {
        setStatus('smartlab-ihc-analysis-status', '正在从当前实验附件载入 IHC 图像...', null);
        await importIhcUploadToState(uploadId, uploadName);
        setStatus('smartlab-ihc-analysis-status', `已从实验附件导入图像：${uploadName}。`, true);
      } catch (error) {
        setStatus('smartlab-ihc-analysis-status', error.message || '导入实验附件图像失败。', false);
      }
    });
    ['ihc-analysis-threshold', 'ihc-analysis-he-threshold', 'ihc-analysis-mode', 'ihc-analysis-region-label'].forEach((id) => {
      byId(id)?.addEventListener('change', rerenderIhc);
      byId(id)?.addEventListener('input', () => {
        if (id === 'ihc-analysis-region-label') {
          const current = getActiveIhcEntry();
          if (current) {
            current.rois[current.activeIndex].label = byId(id)?.value.trim() || getCollectionLabel('ihc', current.activeIndex);
          }
          syncRoiControls('ihc');
        }
      });
    });
    ['ihc-analysis-view-mode', 'ihc-analysis-brightness', 'ihc-analysis-contrast', 'ihc-analysis-invert-view'].forEach((id) => {
      byId(id)?.addEventListener('change', renderIhcOriginalPanel);
      byId(id)?.addEventListener('input', renderIhcOriginalPanel);
    });
    byId('ihc-analysis-reset-view')?.addEventListener('click', () => {
      if (byId('ihc-analysis-view-mode')) byId('ihc-analysis-view-mode').value = 'original';
      if (byId('ihc-analysis-brightness')) byId('ihc-analysis-brightness').value = '0';
      if (byId('ihc-analysis-contrast')) byId('ihc-analysis-contrast').value = '100';
      if (byId('ihc-analysis-invert-view')) byId('ihc-analysis-invert-view').checked = false;
      renderIhcOriginalPanel();
    });
    byId('ihc-analysis-image-select')?.addEventListener('change', (event) => {
      if (!state.ihc?.images?.length) return;
      storeIhcResultSnapshot(getActiveIhcEntry(), 'manual');
      state.ihc.activeImageIndex = clamp(parseInt(event.currentTarget.value || '0', 10) || 0, 0, state.ihc.images.length - 1);
      if (state.ihc.compareImageIndex === state.ihc.activeImageIndex) {
        state.ihc.compareImageIndex = -1;
      }
      syncIhcImageControls();
      syncRoiControls('ihc');
      rerenderIhc();
      applyIhcResultSnapshot(getActiveIhcEntry());
    });
    byId('ihc-analysis-compare-select')?.addEventListener('change', (event) => {
      if (!state.ihc?.images?.length) return;
      const value = `${event.currentTarget.value || ''}`.trim();
      state.ihc.compareImageIndex = value === '' ? -1 : clamp(parseInt(value, 10) || 0, 0, state.ihc.images.length - 1);
      syncIhcImageControls();
      renderIhcOriginalPanel();
    });
    byId('ihc-analysis-roi-select')?.addEventListener('change', (event) => {
      const current = getActiveIhcEntry();
      if (!current) return;
      current.activeIndex = clamp(parseInt(event.currentTarget.value || '0', 10) || 0, 0, current.rois.length - 1);
      syncRoiControls('ihc');
      rerenderIhc();
    });
    byId('ihc-analysis-add-roi')?.addEventListener('click', () => addRoi('ihc', rerenderIhc));
    byId('ihc-analysis-delete-roi')?.addEventListener('click', () => deleteRoi('ihc', rerenderIhc));
    byId('ihc-analysis-apply-roi-template')?.addEventListener('click', () => {
      const count = applyIhcRoiTemplateToAllImages();
      if (!count) {
        setStatus('smartlab-ihc-analysis-status', '请先导入至少一张 IHC 图像。', false);
        return;
      }
      syncRoiControls('ihc');
      rerenderIhc();
      setStatus('smartlab-ihc-analysis-status', `已将当前 ROI 模板应用到 ${count} 张图像。`, true);
    });
    byId('ihc-analysis-run-imagej')?.addEventListener('click', async () => {
      const current = getActiveIhcEntry();
      if (!current) {
        setStatus('smartlab-ihc-analysis-status', '请先导入 IHC 图像。', false);
        return;
      }
      setStatus('smartlab-ihc-analysis-status', '正在把 IHC 图像发送到 ImageJ worker...', null);
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      try {
        const response = await fetch('/smartlab-imagej.php?action=ihc_analyze', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify({
            mode: byId('ihc-analysis-mode')?.value || 'deconv',
            threshold: Number(byId('ihc-analysis-threshold')?.value || '32'),
            he_threshold: Number(byId('ihc-analysis-he-threshold')?.value || '24'),
            rois: current.rois.map((roi, index) => roiToPixels(current.pixels, { ...roi, label: roi.label || `Region ${index + 1}` })),
            image_base64: ihcToDataUrl(false),
          }),
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok || !Array.isArray(payload.results)) {
          throw new Error(payload.error || payload.stderr || `ImageJ analyze failed (${response.status})`);
        }
        if (payload.results.length === 0) {
          throw new Error(payload.stderr || 'ImageJ 已执行，但没有返回任何 ROI 结果。');
        }
        const avgPositiveRatio = payload.results.reduce((sum, row) => sum + Number(row.positive_ratio || 0), 0) / Math.max(payload.results.length, 1);
        const avgHScore = payload.results.reduce((sum, row) => sum + Number(row.h_score || 0), 0) / Math.max(payload.results.length, 1);
        const avgIntensityScore = payload.results.reduce((sum, row) => sum + Number(row.intensity_score || 0), 0) / Math.max(payload.results.length, 1);
        const positiveArea = document.querySelector('input[name="ihc_positive_area"]');
        const staining = document.querySelector('input[name="ihc_staining_intensity"]');
        const hScoreInput = document.querySelector('input[name="ihc_h_score"]');
        if (positiveArea) positiveArea.value = formatDecimal(avgPositiveRatio, 2);
        if (staining) staining.value = formatDecimal(avgIntensityScore, 1);
        if (hScoreInput) hScoreInput.value = formatDecimal(avgHScore, 1);
        current.resultSnapshot = {
          source: 'imagej',
          rows: payload.results.map((row) => ({
            label: row.label,
            positiveRatio: formatDecimal(row.positive_ratio, 2),
            intensityScore: String(row.intensity_score),
            hScore: formatDecimal(row.h_score, 1),
            irs: String(row.irs),
            note: `ImageJ ${payload.engine}; mean ${formatDecimal(row.mean_signal, 2)}; tissue ${formatDecimal(row.tissue_ratio, 1)}%`,
          })),
          positiveArea: formatDecimal(avgPositiveRatio, 2),
          staining: formatDecimal(avgIntensityScore, 1),
          hScore: formatDecimal(avgHScore, 1),
        };
        syncIhcStructuredTable();
        syncIhcAnalysisJson();
        setStatus('smartlab-ihc-analysis-status', `ImageJ 远程分析完成，已回填 ${payload.results.length} 个 ROI。`, true);
      } catch (error) {
        setStatus('smartlab-ihc-analysis-status', error.message || 'ImageJ 远程分析失败。', false);
      }
    });
    byId('if-analysis-export')?.addEventListener('click', () => {
      if (!state.ifp) {
        setStatus('smartlab-if-analysis-status', '请先导入 IF 图像。', false);
        return;
      }
      exportCanvas('smartlab-if-preview', `${(state.ifp.sourceName || 'if-image').replace(/\.[^.]+$/, '')}-annotated.png`);
      setStatus('smartlab-if-analysis-status', '已导出 IF 标注图。', true);
    });
    byId('if-analysis-apply-note')?.addEventListener('click', () => {
      applyAnnotationNote('if-annotation-note', buildIfAnnotationSummary(), 'smartlab-if-analysis-status', 'IF');
    });
    byId('ihc-analysis-export')?.addEventListener('click', () => {
      const current = getActiveIhcEntry();
      if (!current) {
        setStatus('smartlab-ihc-analysis-status', '请先导入 IHC 图像。', false);
        return;
      }
      exportCanvas('smartlab-ihc-preview', `${(current.sourceName || 'ihc-image').replace(/\.[^.]+$/, '')}-annotated.png`);
      setStatus('smartlab-ihc-analysis-status', '已导出 IHC 标注图。', true);
    });
    byId('ihc-analysis-apply-note')?.addEventListener('click', () => {
      applyAnnotationNote('ihc-zoomed-region-ref', buildIhcAnnotationSummary(), 'smartlab-ihc-analysis-status', 'IHC');
    });
    bindRoiCanvas('if', rerenderIf);
    bindRoiCanvas('ihc', rerenderIhc);
    bindWbSelectionCanvas();
    window.addEventListener('mouseup', () => {
      if (state.drag) {
        if (state.drag.kind === 'wb') {
          setStatus('smartlab-wb-analysis-status', isWbManualLineMode(state.drag.mode) ? '已手动调整 lane/band，点击“分析选区”重新生成结果。' : '已选择 WB 区域，点击“分析选区”生成结果。', true);
        } else {
          setStatus(`smartlab-${state.drag.kind}-analysis-status`, '已根据 ROI 重算图像指标。', true);
        }
      }
      state.drag = null;
    });
    rerenderWb();
    autoImportWbUpload();
    refreshBarChart('input[name="if_result_field_label[]"]', 'input[name="if_result_mean_intensity[]"]', 'smartlab-if-chart', 'smartlab-if-chart-empty', 1);
    refreshBarChart('input[name="ihc_result_region_label[]"]', 'input[name="ihc_result_h_score[]"]', 'smartlab-ihc-chart', 'smartlab-ihc-chart-empty', 1);
    restoreIhcAnalysisState();
    restoreIhcImageCache().then((restored) => {
      if (restored) {
        syncIhcImageControls();
        syncRoiControls('ihc');
        rerenderIhc();
        setStatus('smartlab-ihc-analysis-status', '已恢复已保存的多图分析状态和本地缓存图像。', true);
      }
      updateIhcUploadUsageUi();
    });
    updateIhcUploadUsageUi();
    checkImagejBridge();
  });
})();
