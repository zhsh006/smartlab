/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
import { InputType, Malle } from '@deltablot/malle';
import { ApiC } from './api';
import { core } from './core';
import { entity } from './getEntity';
import { on } from './handlers';
import i18next from './i18n';
import { Action, Model } from './interfaces';
import { reloadElements } from './misc';
import { notify } from './notify';
import { fileToAOA } from './spreadsheet-utils';

type SmartlabCell = string | number | boolean | null;
type SmartlabRow = Record<string, string>;
type SmartlabQpcrImportRow = {
  sample: string;
  group: string;
  target: string;
  ct: string;
  referenceCt: string;
  deltaCt: string;
  deltaDeltaCt: string;
  foldChange: string;
  replicateCount: string;
  outlier: string;
  note: string;
};
type SmartlabElisaImportRow = {
  sample: string;
  group: string;
  od450: string;
  concentration: string;
  cvPercent: string;
  note: string;
};
type SmartlabImportOutcome<T> = {
  rows: T[];
  parserLabel: string;
  detail: string;
};
type SmartlabWbBandMetrics = {
  label: string;
  targetSignal: number;
  referenceSignal: number;
  normalizedSignal: number;
  trend: string;
  note: string;
};
type SmartlabWbState = {
  image: HTMLImageElement;
  baseGrayscale: number[];
  laneDividers: number[];
  targetY: number;
  referenceY: number;
  targetHeightRatio: number;
  referenceHeightRatio: number;
  invert: boolean;
  controlLaneIndex: number;
  sourceName: string;
};
type SmartlabImagePixels = {
  width: number;
  height: number;
  rgba: Uint8ClampedArray;
};
type SmartlabRoi = {
  x: number;
  y: number;
  w: number;
  h: number;
};
type SmartlabIfState = {
  image: HTMLImageElement;
  pixels: SmartlabImagePixels;
  roi: SmartlabRoi;
};
type SmartlabIhcState = {
  image: HTMLImageElement;
  pixels: SmartlabImagePixels;
  roi: SmartlabRoi;
};

const HOUSEKEEPING_GENES = new Set([
  '18S',
  'ACTB',
  'B2M',
  'GAPDH',
  'HPRT1',
  'PPIA',
  'RPLP0',
  'TBP',
]);
const CONTROL_GROUP_ALIASES = ['baseline', 'blank', 'control', 'ctrl', 'mock', 'untreated', 'vehicle', 'wt', 'wildtype'];

const normalizeHeader = (value: string): string => value
  .trim()
  .toLowerCase()
  .replace(/[^\w]+/g, '_')
  .replace(/^_+|_+$/g, '');

const normalizeCell = (value: SmartlabCell): string => {
  if (value === null || value === undefined) {
    return '';
  }
  return String(value).trim();
};

const normalizeToken = (value: string): string => value
  .trim()
  .toLowerCase()
  .replace(/[^a-z0-9]+/g, '');

const formatDecimal = (value: number, digits: number): string => value.toFixed(digits).replace(/\.?0+$/, '');

const parseNumber = (value: string): number | null => {
  if (!value) {
    return null;
  }
  const parsed = Number(value.replace(/,/g, '').trim());
  return Number.isFinite(parsed) ? parsed : null;
};

const aoaToRows = (aoa: SmartlabCell[][]): SmartlabRow[] => {
  if (!aoa.length) {
    return [];
  }

  const headers = (aoa[0] || []).map(cell => normalizeHeader(normalizeCell(cell)));
  if (!headers.some(Boolean)) {
    return [];
  }

  return aoa.slice(1).map((row) => {
    const mapped: SmartlabRow = {};
    headers.forEach((header, index) => {
      if (!header) {
        return;
      }
      mapped[header] = normalizeCell(row[index] ?? '');
    });
    return mapped;
  }).filter(row => Object.values(row).some(Boolean));
};

const pick = (row: SmartlabRow, aliases: string[]): string => {
  for (const alias of aliases) {
    const value = row[alias];
    if (value !== undefined && value !== '') {
      return value;
    }
  }
  return '';
};

const hasAnyValue = (row: SmartlabRow, aliases: string[]): boolean => aliases.some(alias => Boolean(row[alias]));

const average = (values: number[]): number | null => values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null;

const meanBy = <T>(items: T[], getter: (item: T) => number | null): number | null => {
  const values = items.map(getter).filter(value => value !== null) as number[];
  return average(values);
};

const isControlGroup = (value: string): boolean => CONTROL_GROUP_ALIASES.includes(normalizeToken(value));

const isHousekeepingGene = (value: string): boolean => HOUSEKEEPING_GENES.has(value.trim().toUpperCase());

const setImportStatus = (kind: 'qpcr' | 'elisa', message: string, tone: 'muted' | 'success' | 'danger' = 'muted'): void => {
  const status = document.getElementById(`smartlab-${kind}-import-status`);
  if (!status) {
    return;
  }
  status.textContent = message;
  status.classList.remove('smallgray', 'text-success', 'text-danger');
  if (tone === 'success') {
    status.classList.add('text-success');
  } else if (tone === 'danger') {
    status.classList.add('text-danger');
  } else {
    status.classList.add('smallgray');
  }
};

const clamp = (value: number, minValue: number, maxValue: number): number => Math.min(Math.max(value, minValue), maxValue);
const getDefaultRoi = (): SmartlabRoi => ({ x: 0.15, y: 0.15, w: 0.7, h: 0.7 });
const clampRoi = (roi: SmartlabRoi): SmartlabRoi => {
  const w = clamp(roi.w, 0.1, 1);
  const h = clamp(roi.h, 0.1, 1);
  const x = clamp(roi.x, 0, 1 - w);
  const y = clamp(roi.y, 0, 1 - h);
  return { x, y, w, h };
};

const ensureRows = (selector: string, count: number): HTMLInputElement[] => {
  const inputs = Array.from(document.querySelectorAll(selector)) as HTMLInputElement[];
  if (!inputs.length) {
    return [];
  }
  const table = inputs[0]?.closest('table');
  const tbody = table?.querySelector('tbody');
  const templateRow = tbody?.querySelector('tr:last-child');
  if (!tbody || !templateRow) {
    return inputs;
  }

  while (tbody.querySelectorAll('tr').length < count) {
    const clone = templateRow.cloneNode(true) as HTMLTableRowElement;
    clone.querySelectorAll('input').forEach((input) => {
      (input as HTMLInputElement).value = '';
    });
    clone.querySelectorAll('select').forEach((select) => {
      (select as HTMLSelectElement).selectedIndex = 0;
    });
    tbody.appendChild(clone);
  }

  return Array.from(document.querySelectorAll(selector)) as HTMLInputElement[];
};

const fillInputSeries = (selector: string, values: string[]): void => {
  const inputs = ensureRows(selector, values.length);
  inputs.forEach((input, index) => {
    input.value = values[index] ?? '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
};

const fillSelectSeries = (selector: string, values: string[]): void => {
  const selects = ensureRows(selector, values.length) as unknown as HTMLSelectElement[];
  selects.forEach((select, index) => {
    const value = values[index] ?? '';
    const option = Array.from(select.options).find(item => item.value === value);
    select.value = option ? value : select.options[0]?.value ?? '';
    select.dispatchEvent(new Event('change', { bubbles: true }));
  });
};

const buildBars = (containerId: string, emptyId: string, bars: Array<{ label: string; value: number; text: string }>): void => {
  const container = document.getElementById(containerId);
  const empty = document.getElementById(emptyId);
  if (!container || !empty) {
    return;
  }

  if (!bars.length) {
    container.classList.add('d-none');
    container.replaceChildren();
    empty.classList.remove('d-none');
    return;
  }

  const max = Math.max(...bars.map(item => item.value), 1);
  container.replaceChildren();
  for (const bar of bars) {
    const node = document.createElement('div');
    node.className = 'bar';
    node.style.setProperty('--v', String(bar.value));
    node.style.setProperty('--max', String(max));
    node.style.setProperty('--label', `"${bar.label}: ${bar.text}"`);
    container.appendChild(node);
  }
  empty.classList.add('d-none');
  container.classList.remove('d-none');
};

const refreshQpcrChart = (): void => {
  const sampleInputs = Array.from(document.querySelectorAll('input[name="qpcr_result_sample_label[]"]')) as HTMLInputElement[];
  const foldInputs = Array.from(document.querySelectorAll('input[name="qpcr_result_fold_change[]"]')) as HTMLInputElement[];
  const bars = sampleInputs.map((input, index) => {
    const label = input.value.trim();
    const value = Number((foldInputs[index]?.value ?? '').trim());
    if (!label || Number.isNaN(value) || value <= 0) {
      return null;
    }
    return { label, value, text: value.toFixed(3) };
  }).filter(Boolean) as Array<{ label: string; value: number; text: string }>;
  buildBars('smartlab-qpcr-chart', 'smartlab-qpcr-chart-empty', bars);
};

const refreshElisaChart = (): void => {
  const sampleInputs = Array.from(document.querySelectorAll('input[name="elisa_result_sample_label[]"]')) as HTMLInputElement[];
  const concentrationInputs = Array.from(document.querySelectorAll('input[name="elisa_result_concentration[]"]')) as HTMLInputElement[];
  const bars = sampleInputs.map((input, index) => {
    const label = input.value.trim();
    const value = Number((concentrationInputs[index]?.value ?? '').trim());
    if (!label || Number.isNaN(value) || value <= 0) {
      return null;
    }
    return { label, value, text: value.toFixed(2) };
  }).filter(Boolean) as Array<{ label: string; value: number; text: string }>;
  buildBars('smartlab-elisa-chart', 'smartlab-elisa-chart-empty', bars);
};

const refreshWbChart = (): void => {
  const sampleInputs = Array.from(document.querySelectorAll('input[name="wb_result_sample_label[]"]')) as HTMLInputElement[];
  const normalizedInputs = Array.from(document.querySelectorAll('input[name="wb_result_normalized_signal[]"]')) as HTMLInputElement[];
  const bars = sampleInputs.map((input, index) => {
    const label = input.value.trim();
    const value = Number((normalizedInputs[index]?.value ?? '').trim());
    if (!label || Number.isNaN(value) || value <= 0) {
      return null;
    }
    return { label, value, text: value.toFixed(3) };
  }).filter(Boolean) as Array<{ label: string; value: number; text: string }>;
  buildBars('smartlab-wb-chart', 'smartlab-wb-chart-empty', bars);
};

const refreshIfChart = (): void => {
  const labels = Array.from(document.querySelectorAll('input[name="if_result_field_label[]"]')) as HTMLInputElement[];
  const values = Array.from(document.querySelectorAll('input[name="if_result_mean_intensity[]"]')) as HTMLInputElement[];
  const bars = labels.map((input, index) => {
    const label = input.value.trim();
    const value = Number((values[index]?.value ?? '').trim());
    if (!label || Number.isNaN(value) || value <= 0) {
      return null;
    }
    return { label, value, text: value.toFixed(1) };
  }).filter(Boolean) as Array<{ label: string; value: number; text: string }>;
  buildBars('smartlab-if-chart', 'smartlab-if-chart-empty', bars);
};

const refreshIhcChart = (): void => {
  const labels = Array.from(document.querySelectorAll('input[name="ihc_result_region_label[]"]')) as HTMLInputElement[];
  const values = Array.from(document.querySelectorAll('input[name="ihc_result_h_score[]"]')) as HTMLInputElement[];
  const bars = labels.map((input, index) => {
    const label = input.value.trim();
    const value = Number((values[index]?.value ?? '').trim());
    if (!label || Number.isNaN(value) || value <= 0) {
      return null;
    }
    return { label, value, text: value.toFixed(1) };
  }).filter(Boolean) as Array<{ label: string; value: number; text: string }>;
  buildBars('smartlab-ihc-chart', 'smartlab-ihc-chart-empty', bars);
};

const readImageDataUrl = (file: File): Promise<string> => new Promise((resolve, reject) => {
  const reader = new FileReader();
  reader.onload = () => resolve(String(reader.result || ''));
  reader.onerror = () => reject(new Error('无法读取图像文件。'));
  reader.readAsDataURL(file);
});

const loadImageElement = (dataUrl: string): Promise<HTMLImageElement> => new Promise((resolve, reject) => {
  const image = new Image();
  image.onload = () => resolve(image);
  image.onerror = () => reject(new Error('无法解析图像。请上传 PNG / JPG / TIFF 转换后的图片。'));
  image.src = dataUrl;
});

const readImagePixels = async (file: File): Promise<{ image: HTMLImageElement; pixels: SmartlabImagePixels }> => {
  const dataUrl = await readImageDataUrl(file);
  const image = await loadImageElement(dataUrl);
  const canvas = document.createElement('canvas');
  canvas.width = image.width;
  canvas.height = image.height;
  const context = canvas.getContext('2d');
  if (!context) {
    throw new Error('浏览器不支持图像分析。');
  }
  context.drawImage(image, 0, 0);
  const imageData = context.getImageData(0, 0, image.width, image.height);
  return {
    image,
    pixels: {
      width: image.width,
      height: image.height,
      rgba: imageData.data,
    },
  };
};

const renderMaskPreview = (
  canvasId: string,
  emptyId: string,
  image: HTMLImageElement,
  pixels: SmartlabImagePixels,
  mask: boolean[],
  color: [number, number, number],
  alpha = 0.35,
  roi?: SmartlabRoi,
): void => {
  const canvas = document.getElementById(canvasId) as HTMLCanvasElement | null;
  const empty = document.getElementById(emptyId);
  if (!canvas) {
    return;
  }
  const context = canvas.getContext('2d');
  if (!context) {
    return;
  }

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
    overlay.data[offset + 3] = Math.round(255 * alpha);
  }
  context.putImageData(overlay, 0, 0);
  if (roi) {
    const x = roi.x * image.width;
    const y = roi.y * image.height;
    const w = roi.w * image.width;
    const h = roi.h * image.height;
    context.strokeStyle = 'rgba(17, 24, 39, 0.95)';
    context.lineWidth = Math.max(2, image.width / 300);
    context.strokeRect(x, y, w, h);
    context.fillStyle = 'rgba(17, 24, 39, 0.9)';
    context.fillRect(x + w - 10, y + h - 10, 10, 10);
  }
  canvas.classList.remove('d-none');
  if (empty) {
    empty.classList.add('d-none');
  }
};

const setImageStatus = (kind: 'if' | 'ihc', message: string, tone: 'muted' | 'success' | 'danger' = 'muted'): void => {
  const status = document.getElementById(`smartlab-${kind}-analysis-status`);
  if (!status) {
    return;
  }
  status.textContent = message;
  status.classList.remove('smallgray', 'text-success', 'text-danger');
  if (tone === 'success') {
    status.classList.add('text-success');
  } else if (tone === 'danger') {
    status.classList.add('text-danger');
  } else {
    status.classList.add('smallgray');
  }
};

const getRoiPixels = (pixels: SmartlabImagePixels, roi: SmartlabRoi): Array<{ index: number; r: number; g: number; b: number }> => {
  const xStart = Math.floor(roi.x * pixels.width);
  const yStart = Math.floor(roi.y * pixels.height);
  const xEnd = Math.floor((roi.x + roi.w) * pixels.width);
  const yEnd = Math.floor((roi.y + roi.h) * pixels.height);
  const selected: Array<{ index: number; r: number; g: number; b: number }> = [];
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

const setWbAnalysisStatus = (message: string, tone: 'muted' | 'success' | 'danger' = 'muted'): void => {
  const status = document.getElementById('smartlab-wb-analysis-status');
  if (!status) {
    return;
  }
  status.textContent = message;
  status.classList.remove('smallgray', 'text-success', 'text-danger');
  if (tone === 'success') {
    status.classList.add('text-success');
  } else if (tone === 'danger') {
    status.classList.add('text-danger');
  } else {
    status.classList.add('smallgray');
  }
};

let wbState: SmartlabWbState | null = null;
let wbDragHandle: { type: 'divider' | 'target_center' | 'target_top' | 'target_bottom' | 'reference_center' | 'reference_top' | 'reference_bottom'; index?: number } | null = null;
let ifState: SmartlabIfState | null = null;
let ihcState: SmartlabIhcState | null = null;
let imageRoiDrag: { kind: 'if' | 'ihc'; mode: 'move' | 'resize'; startX: number; startY: number; roi: SmartlabRoi } | null = null;

const getWbLaneCount = (): number => wbState ? wbState.laneDividers.length + 1 : 0;

const syncWbControlsFromState = (): void => {
  if (!wbState) {
    return;
  }
  const laneInput = document.getElementById('wb-analysis-lanes') as HTMLInputElement | null;
  const targetBandInput = document.getElementById('wb-analysis-target-band') as HTMLInputElement | null;
  const referenceBandInput = document.getElementById('wb-analysis-reference-band') as HTMLInputElement | null;
  const bandWindowInput = document.getElementById('wb-analysis-band-window') as HTMLInputElement | null;
  const invertInput = document.getElementById('wb-analysis-invert') as HTMLInputElement | null;
  const controlLaneSelect = document.getElementById('wb-analysis-control-lane') as HTMLSelectElement | null;
  if (laneInput) {
    laneInput.value = String(getWbLaneCount());
  }
  if (targetBandInput) {
    targetBandInput.value = String(Math.round(wbState.targetY * 100));
  }
  if (referenceBandInput) {
    referenceBandInput.value = String(Math.round(wbState.referenceY * 100));
  }
  if (bandWindowInput) {
    bandWindowInput.value = String(Math.round(((wbState.targetHeightRatio + wbState.referenceHeightRatio) / 2) * 100));
  }
  if (invertInput) {
    invertInput.checked = wbState.invert;
  }
  if (controlLaneSelect) {
    controlLaneSelect.replaceChildren();
    Array.from({ length: getWbLaneCount() }, (_, index) => index).forEach((index) => {
      const option = document.createElement('option');
      option.value = String(index);
      option.textContent = `Lane ${index + 1}`;
      if (index === wbState.controlLaneIndex) {
        option.selected = true;
      }
      controlLaneSelect.appendChild(option);
    });
  }
};

const getWbGrayscale = (state: SmartlabWbState): number[] => state.invert
  ? state.baseGrayscale.map(value => 255 - value)
  : state.baseGrayscale;

const renderWbPreviewCanvas = (
  canvas: HTMLCanvasElement,
  image: HTMLImageElement,
  grayscale: number[],
  laneDividers: number[],
  targetY: number,
  referenceY: number,
  targetHeightRatio: number,
  referenceHeightRatio: number,
): void => {
  const context = canvas.getContext('2d');
  if (!context) {
    return;
  }

  canvas.width = image.width;
  canvas.height = image.height;
  context.drawImage(image, 0, 0);

  context.save();
  context.strokeStyle = 'rgba(18, 74, 115, 0.9)';
  context.lineWidth = Math.max(1, image.width / 300);
  for (const divider of laneDividers) {
    const x = divider * image.width;
    context.beginPath();
    context.moveTo(x, 0);
    context.lineTo(x, image.height);
    context.stroke();
    context.fillStyle = 'rgba(18, 74, 115, 0.9)';
    context.beginPath();
    context.arc(x, 12, 5, 0, Math.PI * 2);
    context.fill();
  }

  const drawBandBox = (ratio: number, heightRatio: number, color: string, label: string): void => {
    const centerY = clamp(Math.round(ratio * image.height), 0, image.height - 1);
    const halfHeight = Math.max(4, Math.round((heightRatio * image.height) / 2));
    const topY = clamp(centerY - halfHeight, 0, image.height - 1);
    const bottomY = clamp(centerY + halfHeight, 0, image.height - 1);
    context.fillStyle = color.replace('0.95', '0.14').replace('0.9', '0.14');
    context.strokeStyle = color;
    context.fillRect(0, topY, image.width, Math.max(4, bottomY - topY));
    context.strokeRect(0, topY, image.width, Math.max(4, bottomY - topY));
    context.fillStyle = color;
    context.font = `${Math.max(12, image.width / 35)}px sans-serif`;
    context.fillText(label, 12, Math.max(14, topY - 6));
    [topY, centerY, bottomY].forEach((y) => {
      context.beginPath();
      context.arc(image.width - 16, y, 5, 0, Math.PI * 2);
      context.fill();
    });
  };

  drawBandBox(targetY, targetHeightRatio, 'rgba(215, 73, 47, 0.95)', 'Target ROI');
  drawBandBox(referenceY, referenceHeightRatio, 'rgba(37, 142, 95, 0.95)', 'Reference ROI');
  context.restore();

  const profileCanvas = document.getElementById('smartlab-wb-profile') as HTMLCanvasElement | null;
  const profileEmpty = document.getElementById('smartlab-wb-profile-empty');
  if (!profileCanvas) {
    return;
  }
  const profileContext = profileCanvas.getContext('2d');
  if (!profileContext) {
    return;
  }

  profileCanvas.width = Math.max(320, image.width);
  profileCanvas.height = 140;
  profileContext.clearRect(0, 0, profileCanvas.width, profileCanvas.height);
  const columnMeans: number[] = [];
  for (let x = 0; x < image.width; x += 1) {
    let total = 0;
    for (let y = 0; y < image.height; y += 1) {
      total += grayscale[(y * image.width) + x] || 0;
    }
    columnMeans.push(total / image.height);
  }

  const maxValue = Math.max(...columnMeans, 1);
  profileContext.strokeStyle = '#124a73';
  profileContext.lineWidth = 2;
  profileContext.beginPath();
  columnMeans.forEach((value, index) => {
    const x = (index / Math.max(columnMeans.length - 1, 1)) * profileCanvas.width;
    const y = profileCanvas.height - ((value / maxValue) * (profileCanvas.height - 20)) - 10;
    if (index === 0) {
      profileContext.moveTo(x, y);
    } else {
      profileContext.lineTo(x, y);
    }
  });
  profileContext.stroke();
  if (profileEmpty) {
    profileEmpty.classList.add('d-none');
  }
  profileCanvas.classList.remove('d-none');
};

const collectWbMetrics = (state: SmartlabWbState): SmartlabWbBandMetrics[] => {
  const boundaries = [0, ...state.laneDividers, 1];
  const metrics: SmartlabWbBandMetrics[] = [];
  const grayscale = getWbGrayscale(state);
  const collectBandSignal = (laneIndex: number, centerRatio: number, heightRatio: number): number => {
    const xStart = Math.floor(boundaries[laneIndex] * state.image.width);
    const xEnd = Math.floor(boundaries[laneIndex + 1] * state.image.width);
    const centerY = clamp(Math.round(centerRatio * state.image.height), 0, state.image.height - 1);
    const halfHeight = Math.max(2, Math.round((heightRatio * state.image.height) / 2));
    const yStart = clamp(centerY - halfHeight, 0, state.image.height - 1);
    const yEnd = clamp(centerY + halfHeight, 0, state.image.height - 1);
    let total = 0;
    let pixels = 0;
    for (let y = yStart; y <= yEnd; y += 1) {
      for (let x = xStart; x < xEnd; x += 1) {
        total += grayscale[(y * state.image.width) + x] || 0;
        pixels += 1;
      }
    }
    return pixels ? total / pixels : 0;
  };

  for (let lane = 0; lane < boundaries.length - 1; lane += 1) {
    const targetSignal = collectBandSignal(lane, state.targetY, state.targetHeightRatio);
    const referenceSignal = collectBandSignal(lane, state.referenceY, state.referenceHeightRatio);
    const normalizedSignal = referenceSignal > 0 ? targetSignal / referenceSignal : 0;
    metrics.push({
      label: `Lane ${lane + 1}`,
      targetSignal,
      referenceSignal,
      normalizedSignal,
      trend: '',
      note: `target ${Math.round(state.targetY * 100)}% h${Math.round(state.targetHeightRatio * 100)}% / reference ${Math.round(state.referenceY * 100)}% h${Math.round(state.referenceHeightRatio * 100)}%`,
    });
  }

  const safeControlIndex = clamp(state.controlLaneIndex, 0, Math.max(metrics.length - 1, 0));
  const controlValue = metrics[safeControlIndex]?.normalizedSignal || 0;
  metrics.forEach((metric, index) => {
    if (controlValue > 0) {
      const ratio = metric.normalizedSignal / controlValue;
      metric.trend = ratio > 1.15 ? 'up' : ratio < 0.85 ? 'down' : 'stable';
    } else {
      metric.trend = index === safeControlIndex ? 'baseline' : '';
    }
  });
  if (metrics[safeControlIndex]) {
    metrics[safeControlIndex].trend = 'baseline';
  }

  return metrics;
};

const applyWbMetrics = (metrics: SmartlabWbBandMetrics[]): void => {
  fillInputSeries('input[name="wb_result_sample_label[]"]', metrics.map(metric => metric.label));
  fillInputSeries('input[name="wb_result_target_signal[]"]', metrics.map(metric => formatDecimal(metric.targetSignal, 2)));
  fillInputSeries('input[name="wb_result_reference_signal[]"]', metrics.map(metric => formatDecimal(metric.referenceSignal, 2)));
  fillInputSeries('input[name="wb_result_normalized_signal[]"]', metrics.map(metric => formatDecimal(metric.normalizedSignal, 3)));
  fillInputSeries('input[name="wb_result_trend[]"]', metrics.map(metric => metric.trend));
  fillInputSeries('input[name="wb_result_note[]"]', metrics.map(metric => metric.note));
  refreshWbChart();
};

const exportWbPreview = async (): Promise<void> => {
  if (!wbState) {
    throw new Error('请先导入 WB 图像并完成分析。');
  }
  const previewCanvas = document.getElementById('smartlab-wb-preview') as HTMLCanvasElement | null;
  if (!previewCanvas) {
    throw new Error('未找到 WB 预览图。');
  }
  const blob = await new Promise<Blob | null>((resolve) => {
    previewCanvas.toBlob((result) => resolve(result), 'image/png');
  });
  if (!blob) {
    throw new Error('无法导出 WB 标注图。');
  }
  const filenameBase = wbState.sourceName.replace(/\.[^.]+$/, '') || 'wb-analysis';
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${filenameBase}-annotated.png`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
};

const rerenderIfAnalysis = (): void => {
  if (!ifState) {
    return;
  }
  const thresholdInput = document.getElementById('if-analysis-threshold') as HTMLInputElement | null;
  const nuclearInput = document.getElementById('if-analysis-nuclear-threshold') as HTMLInputElement | null;
  const fieldLabelInput = document.getElementById('if-analysis-field-label') as HTMLInputElement | null;
  if (!thresholdInput || !nuclearInput || !fieldLabelInput) {
    return;
  }

  const threshold = clamp(parseInt(thresholdInput.value || '160', 10) || 160, 1, 255);
  const nuclearThreshold = clamp(parseInt(nuclearInput.value || '120', 10) || 120, 1, 255);
  const { image, pixels, roi } = ifState;
  const positiveMask = Array.from({ length: pixels.width * pixels.height }, () => false);
  let positiveCount = 0;
  let nuclearCount = 0;
  let totalSignal = 0;
  let colocPositive = 0;

  const roiPixels = getRoiPixels(pixels, roi);
  for (const pixel of roiPixels) {
    const { index, r, g, b } = pixel;
    const signal = Math.max(g, r);
    const nuclearSignal = b;
    const isPositive = signal >= threshold;
    const isNuclear = nuclearSignal >= nuclearThreshold;
    positiveMask[index] = isPositive;
    if (isPositive) {
      positiveCount += 1;
      totalSignal += signal;
    }
    if (isNuclear) {
      nuclearCount += 1;
    }
    if (isPositive && isNuclear) {
      colocPositive += 1;
    }
  }

  const fieldLabel = fieldLabelInput.value.trim() || 'Field 1';
  const roiArea = Math.max(roiPixels.length, 1);
  const positiveRatio = (positiveCount / roiArea) * 100;
  const meanIntensity = positiveCount > 0 ? totalSignal / positiveCount : 0;
  const estimatedCellCount = Math.max(1, Math.round(nuclearCount / 180));
  const coloc = positiveCount > 0 ? (colocPositive / positiveCount) : 0;

  fillInputSeries('input[name="if_result_field_label[]"]', [fieldLabel]);
  fillInputSeries('input[name="if_result_cell_count[]"]', [String(estimatedCellCount)]);
  fillInputSeries('input[name="if_result_positive_ratio[]"]', [formatDecimal(positiveRatio, 2)]);
  fillInputSeries('input[name="if_result_mean_intensity[]"]', [formatDecimal(meanIntensity, 1)]);
  fillInputSeries('input[name="if_result_colocalization[]"]', [formatDecimal(coloc, 3)]);
  fillInputSeries('input[name="if_result_note[]"]', [`threshold ${threshold}, nuclear ${nuclearThreshold}`]);
  const fieldCountInput = document.querySelector('input[name="if_field_count"]') as HTMLInputElement | null;
  const positiveRatioInput = document.querySelector('input[name="if_positive_ratio"]') as HTMLInputElement | null;
  const meanIntensityInput = document.querySelector('input[name="if_mean_intensity"]') as HTMLInputElement | null;
  const colocInput = document.querySelector('input[name="if_colocalization"]') as HTMLInputElement | null;
  if (fieldCountInput) {
    fieldCountInput.value = '1';
  }
  if (positiveRatioInput) {
    positiveRatioInput.value = formatDecimal(positiveRatio, 2);
  }
  if (meanIntensityInput) {
    meanIntensityInput.value = formatDecimal(meanIntensity, 1);
  }
  if (colocInput) {
    colocInput.value = formatDecimal(coloc, 3);
  }
  refreshIfChart();
  renderMaskPreview('smartlab-if-preview', 'smartlab-if-preview-empty', image, pixels, positiveMask, [42, 157, 143], 0.35, roi);
  const previewMeta = document.getElementById('smartlab-if-preview-meta');
  if (previewMeta) {
    previewMeta.textContent = `${image.width}×${image.height}px，ROI ${Math.round(roi.w * 100)}%×${Math.round(roi.h * 100)}%，阳性比例 ${formatDecimal(positiveRatio, 2)}%，平均强度 ${formatDecimal(meanIntensity, 1)}。`;
  }
  setImageStatus('if', `已回填 IF 结果：cell count ${estimatedCellCount}，positive ratio ${formatDecimal(positiveRatio, 2)}%。`, 'success');
};

const analyzeIfImage = async (file: File): Promise<void> => {
  const { image, pixels } = await readImagePixels(file);
  ifState = { image, pixels, roi: getDefaultRoi() };
  rerenderIfAnalysis();
};

const rerenderIhcAnalysis = (): void => {
  if (!ihcState) {
    return;
  }
  const thresholdInput = document.getElementById('ihc-analysis-threshold') as HTMLInputElement | null;
  const regionLabelInput = document.getElementById('ihc-analysis-region-label') as HTMLInputElement | null;
  if (!thresholdInput || !regionLabelInput) {
    return;
  }

  const threshold = clamp(parseInt(thresholdInput.value || '32', 10) || 32, 1, 255);
  const { image, pixels, roi } = ihcState;
  const positiveMask = Array.from({ length: pixels.width * pixels.height }, () => false);
  let positiveCount = 0;
  let totalBrownScore = 0;

  const roiPixels = getRoiPixels(pixels, roi);
  for (const pixel of roiPixels) {
    const { index, r, g, b } = pixel;
    const brownScore = Math.max(0, r - ((g * 0.7) + (b * 0.35)));
    const isPositive = brownScore >= threshold;
    positiveMask[index] = isPositive;
    if (isPositive) {
      positiveCount += 1;
      totalBrownScore += brownScore;
    }
  }

  const regionLabel = regionLabelInput.value.trim() || 'Whole region';
  const roiArea = Math.max(roiPixels.length, 1);
  const positiveRatio = (positiveCount / roiArea) * 100;
  const meanBrownScore = positiveCount > 0 ? totalBrownScore / positiveCount : 0;
  const intensityScore = meanBrownScore >= 96 ? 3 : meanBrownScore >= 64 ? 2 : meanBrownScore >= 24 ? 1 : 0;
  const hScore = positiveRatio * intensityScore;
  const percentageCategory = positiveRatio >= 80 ? 4 : positiveRatio >= 50 ? 3 : positiveRatio >= 10 ? 2 : positiveRatio > 0 ? 1 : 0;
  const irs = percentageCategory * intensityScore;

  fillInputSeries('input[name="ihc_result_region_label[]"]', [regionLabel]);
  fillInputSeries('input[name="ihc_result_positive_ratio[]"]', [formatDecimal(positiveRatio, 2)]);
  fillInputSeries('input[name="ihc_result_intensity_score[]"]', [String(intensityScore)]);
  fillInputSeries('input[name="ihc_result_h_score[]"]', [formatDecimal(hScore, 1)]);
  fillInputSeries('input[name="ihc_result_irs_score[]"]', [String(irs)]);
  fillInputSeries('input[name="ihc_result_note[]"]', [`brown threshold ${threshold}`]);
  const positiveAreaInput = document.querySelector('input[name="ihc_positive_area"]') as HTMLInputElement | null;
  const intensityInput = document.querySelector('input[name="ihc_staining_intensity"]') as HTMLInputElement | null;
  const hScoreInput = document.querySelector('input[name="ihc_h_score"]') as HTMLInputElement | null;
  if (positiveAreaInput) {
    positiveAreaInput.value = formatDecimal(positiveRatio, 2);
  }
  if (intensityInput) {
    intensityInput.value = String(intensityScore);
  }
  if (hScoreInput) {
    hScoreInput.value = formatDecimal(hScore, 1);
  }
  refreshIhcChart();
  renderMaskPreview('smartlab-ihc-preview', 'smartlab-ihc-preview-empty', image, pixels, positiveMask, [161, 98, 7], 0.38, roi);
  const previewMeta = document.getElementById('smartlab-ihc-preview-meta');
  if (previewMeta) {
    previewMeta.textContent = `${image.width}×${image.height}px，ROI ${Math.round(roi.w * 100)}%×${Math.round(roi.h * 100)}%，阳性面积 ${formatDecimal(positiveRatio, 2)}%，H-score ${formatDecimal(hScore, 1)}。`;
  }
  setImageStatus('ihc', `已回填 IHC 评分：positive area ${formatDecimal(positiveRatio, 2)}%，H-score ${formatDecimal(hScore, 1)}。`, 'success');
};

const analyzeIhcImage = async (file: File): Promise<void> => {
  const { image, pixels } = await readImagePixels(file);
  ihcState = { image, pixels, roi: getDefaultRoi() };
  rerenderIhcAnalysis();
};

const rerenderWbState = (): void => {
  if (!wbState) {
    return;
  }
  const previewCanvas = document.getElementById('smartlab-wb-preview') as HTMLCanvasElement | null;
  const previewEmpty = document.getElementById('smartlab-wb-preview-empty');
  const previewMeta = document.getElementById('smartlab-wb-preview-meta');
  if (!previewCanvas) {
    return;
  }

  renderWbPreviewCanvas(
    previewCanvas,
    wbState.image,
    getWbGrayscale(wbState),
    wbState.laneDividers,
    wbState.targetY,
    wbState.referenceY,
    wbState.targetHeightRatio,
    wbState.referenceHeightRatio,
  );
  previewCanvas.classList.remove('d-none');
  if (previewEmpty) {
    previewEmpty.classList.add('d-none');
  }
  if (previewMeta) {
    previewMeta.textContent = `${wbState.image.width}×${wbState.image.height}px，${getWbLaneCount()} 个 lane，可拖动蓝色 lane 线，以及红/绿 ROI 框的中心和上下边缘。`;
  }

  applyWbMetrics(collectWbMetrics(wbState));
  syncWbControlsFromState();
};

const analyzeWesternBlotImage = async (file: File): Promise<void> => {
  const laneInput = document.getElementById('wb-analysis-lanes') as HTMLInputElement | null;
  const targetBandInput = document.getElementById('wb-analysis-target-band') as HTMLInputElement | null;
  const referenceBandInput = document.getElementById('wb-analysis-reference-band') as HTMLInputElement | null;
  const bandWindowInput = document.getElementById('wb-analysis-band-window') as HTMLInputElement | null;
  const invertInput = document.getElementById('wb-analysis-invert') as HTMLInputElement | null;
  const previewCanvas = document.getElementById('smartlab-wb-preview') as HTMLCanvasElement | null;
  const previewEmpty = document.getElementById('smartlab-wb-preview-empty');
  const previewMeta = document.getElementById('smartlab-wb-preview-meta');

  if (!laneInput || !targetBandInput || !referenceBandInput || !bandWindowInput || !invertInput || !previewCanvas) {
    return;
  }

  const laneCount = clamp(parseInt(laneInput.value || '4', 10) || 4, 1, 24);
  const targetY = clamp((parseFloat(targetBandInput.value || '35') || 35) / 100, 0.05, 0.95);
  const referenceY = clamp((parseFloat(referenceBandInput.value || '75') || 75) / 100, 0.05, 0.95);
  const bandWindowRatio = clamp((parseFloat(bandWindowInput.value || '8') || 8) / 100, 0.02, 0.25);
  const invert = invertInput.checked;

  const dataUrl = await readImageDataUrl(file);
  const image = await loadImageElement(dataUrl);
  const workingCanvas = document.createElement('canvas');
  workingCanvas.width = image.width;
  workingCanvas.height = image.height;
  const workingContext = workingCanvas.getContext('2d');
  if (!workingContext) {
    throw new Error('浏览器不支持图像分析。');
  }
  workingContext.drawImage(image, 0, 0);
  const imageData = workingContext.getImageData(0, 0, image.width, image.height);
  const baseGrayscale: number[] = [];
  for (let index = 0; index < imageData.data.length; index += 4) {
    const r = imageData.data[index] || 0;
    const g = imageData.data[index + 1] || 0;
    const b = imageData.data[index + 2] || 0;
    const luminance = (0.299 * r) + (0.587 * g) + (0.114 * b);
    baseGrayscale.push(luminance);
  }

  wbState = {
    image,
    baseGrayscale,
    laneDividers: Array.from({ length: laneCount - 1 }, (_, index) => (index + 1) / laneCount),
    targetY,
    referenceY,
    targetHeightRatio: bandWindowRatio,
    referenceHeightRatio: bandWindowRatio,
    invert,
    controlLaneIndex: 0,
    sourceName: file.name,
  };
  rerenderWbState();
  setWbAnalysisStatus(`已分析 ${laneCount} 个 lane，并回填 target/reference/normalized 信号。`, 'success');
};

const parseDirectQpcrRows = (rows: SmartlabRow[]): SmartlabQpcrImportRow[] => rows.map((row) => {
  const ctValue = pick(row, ['ct_value', 'ct', 'cq', 'target_ct']);
  const referenceCt = pick(row, ['reference_ct', 'ref_ct', 'housekeeping_ct', 'reference']);
  const deltaCt = pick(row, ['delta_ct', 'dct']) || (
    ctValue !== '' && referenceCt !== ''
      ? String(Number(ctValue) - Number(referenceCt))
      : ''
  );
  const deltaDeltaCt = pick(row, ['delta_delta_ct', 'ddct']);
  const foldChange = pick(row, ['fold_change', 'fold', 'foldchange']) || (
    deltaDeltaCt !== '' ? String(Math.pow(2, -1 * Number(deltaDeltaCt))) : ''
  );

  return {
    sample: pick(row, ['sample', 'sample_label', 'sample_id', 'well_sample']),
    group: pick(row, ['group', 'group_label', 'condition']),
    target: pick(row, ['target', 'target_gene', 'gene']),
    ct: ctValue,
    referenceCt,
    deltaCt,
    deltaDeltaCt,
    foldChange,
    replicateCount: pick(row, ['replicate_count', 'replicates', 'n']),
    outlier: pick(row, ['is_outlier', 'outlier']) === '1' || pick(row, ['is_outlier', 'outlier']).toLowerCase() === 'yes' ? '1' : '0',
    note: pick(row, ['note', 'comment', 'remarks']),
  };
}).filter(row => Object.values(row).some(Boolean));

const parseInstrumentQpcrRows = (rows: SmartlabRow[], parserLabel: string): SmartlabImportOutcome<SmartlabQpcrImportRow> => {
  const rawRows = rows.map((row) => ({
    sample: pick(row, ['sample_name', 'sample', 'sample_id', 'well_sample', 'sampleid', 'sample_name_1']),
    group: pick(row, ['group', 'group_name', 'sample_type', 'condition']),
    target: pick(row, ['target_name', 'target', 'detector_name', 'gene', 'assay']),
    ct: pick(row, ['ct', 'ct_mean', 'ctvalue', 'cq', 'cq_mean', 'crt', 'cp']),
    well: pick(row, ['well', 'well_position']),
    note: pick(row, ['note', 'comment', 'remarks', 'flag']),
  })).filter(row => row.sample && row.target && row.ct);

  if (!rawRows.length) {
    throw new Error('未识别到 qPCR 样本、目标基因和 Ct/Cq 列。');
  }

  const grouped = new Map<string, typeof rawRows>();
  for (const row of rawRows) {
    const key = `${row.sample}__${row.group || ''}`;
    const bucket = grouped.get(key) ?? [];
    bucket.push(row);
    grouped.set(key, bucket);
  }

  const normalized: SmartlabQpcrImportRow[] = [];
  for (const [, sampleRows] of grouped) {
    const referenceMean = meanBy(sampleRows.filter(row => isHousekeepingGene(row.target)), row => parseNumber(row.ct));
    const targets = Array.from(new Set(sampleRows.filter(row => !isHousekeepingGene(row.target)).map(row => row.target)));
    const targetsToUse = targets.length ? targets : Array.from(new Set(sampleRows.map(row => row.target)));

    for (const target of targetsToUse) {
      const targetRows = sampleRows.filter(row => row.target === target);
      const ctMean = meanBy(targetRows, row => parseNumber(row.ct));
      if (ctMean === null) {
        continue;
      }
      const deltaCt = referenceMean === null || isHousekeepingGene(target) ? null : ctMean - referenceMean;
      normalized.push({
        sample: targetRows[0].sample,
        group: targetRows[0].group,
        target,
        ct: formatDecimal(ctMean, 3),
        referenceCt: referenceMean === null ? '' : formatDecimal(referenceMean, 3),
        deltaCt: deltaCt === null ? '' : formatDecimal(deltaCt, 3),
        deltaDeltaCt: '',
        foldChange: '',
        replicateCount: String(targetRows.length),
        outlier: '0',
        note: targetRows.map(row => row.well).filter(Boolean).join(', '),
      });
    }
  }

  const baselineValuesByTarget = new Map<string, number[]>();
  for (const row of normalized) {
    const deltaCt = parseNumber(row.deltaCt);
    if (deltaCt === null) {
      continue;
    }
    if (!isControlGroup(row.group)) {
      continue;
    }
    const key = row.target;
    const bucket = baselineValuesByTarget.get(key) ?? [];
    bucket.push(deltaCt);
    baselineValuesByTarget.set(key, bucket);
  }
  const baselineByTarget = new Map<string, number>();
  baselineValuesByTarget.forEach((values, target) => {
    const mean = average(values);
    if (mean !== null) {
      baselineByTarget.set(target, mean);
    }
  });
  if (!baselineByTarget.size) {
    const firstGroupByTarget = new Map<string, string>();
    for (const row of normalized) {
      if (!row.deltaCt) {
        continue;
      }
      if (!firstGroupByTarget.has(row.target)) {
        firstGroupByTarget.set(row.target, row.group);
      }
    }
    for (const [target, group] of firstGroupByTarget.entries()) {
      const values = normalized
        .filter(row => row.target === target && row.group === group)
        .map(row => parseNumber(row.deltaCt))
        .filter(value => value !== null) as number[];
      const mean = average(values);
      if (mean !== null) {
        baselineByTarget.set(target, mean);
      }
    }
  }

  normalized.forEach((row) => {
    const deltaCt = parseNumber(row.deltaCt);
    const baseline = baselineByTarget.get(row.target);
    if (deltaCt === null || baseline === undefined) {
      return;
    }
    const deltaDeltaCt = deltaCt - baseline;
    row.deltaDeltaCt = formatDecimal(deltaDeltaCt, 3);
    row.foldChange = formatDecimal(Math.pow(2, -1 * deltaDeltaCt), 3);
  });

  return {
    rows: normalized.filter(row => Object.values(row).some(Boolean)),
    parserLabel,
    detail: baselineByTarget.size
      ? '已按样本聚合重复孔，并自动估算 reference Ct、ΔCt、ΔΔCt 与 Fold Change。'
      : '已按样本聚合重复孔，并自动估算 reference Ct 与 ΔCt。',
  };
};

const parseQpcrImport = (rows: SmartlabRow[]): SmartlabImportOutcome<SmartlabQpcrImportRow> => {
  const headers = new Set(rows.flatMap(row => Object.keys(row)));
  if (headers.has('sample_name') || headers.has('target_name') || headers.has('well_position')) {
    return parseInstrumentQpcrRows(rows, 'QuantStudio / ABI 导出');
  }
  if (headers.has('cq') || headers.has('cq_mean') || headers.has('detector_name')) {
    return parseInstrumentQpcrRows(rows, 'Bio-Rad CFX 导出');
  }

  const normalized = parseDirectQpcrRows(rows);
  if (!normalized.length) {
    throw new Error('未识别到 qPCR 结果列。请确认文件包含 sample / ct / reference_ct / fold_change 等列。');
  }

  const instrumentLike = rows.some(row => hasAnyValue(row, ['sample_name', 'sample']) && hasAnyValue(row, ['target_name', 'target']) && hasAnyValue(row, ['ct', 'cq']));
  if (instrumentLike) {
    return parseInstrumentQpcrRows(rows, 'qPCR 仪器结果表');
  }

  return {
    rows: normalized,
    parserLabel: '通用 qPCR 结果表',
    detail: '已按表头直接映射到结构化结果表。',
  };
};

const importQpcrRows = (rows: SmartlabRow[]): SmartlabImportOutcome<SmartlabQpcrImportRow> => {
  const outcome = parseQpcrImport(rows);
  const normalized = outcome.rows;
  if (!normalized.length) {
    throw new Error('未识别到 qPCR 结果列。请确认文件包含 sample / ct / reference_ct / fold_change 等列。');
  }

  fillInputSeries('input[name="qpcr_result_sample_label[]"]', normalized.map(row => row.sample));
  fillInputSeries('input[name="qpcr_result_group_label[]"]', normalized.map(row => row.group));
  fillInputSeries('input[name="qpcr_result_target_gene[]"]', normalized.map(row => row.target));
  fillInputSeries('input[name="qpcr_result_ct_value[]"]', normalized.map(row => row.ct));
  fillInputSeries('input[name="qpcr_result_reference_ct[]"]', normalized.map(row => row.referenceCt));
  fillInputSeries('input[name="qpcr_result_delta_ct[]"]', normalized.map(row => row.deltaCt));
  fillInputSeries('input[name="qpcr_result_delta_delta_ct[]"]', normalized.map(row => row.deltaDeltaCt));
  fillInputSeries('input[name="qpcr_result_fold_change[]"]', normalized.map(row => row.foldChange));
  fillInputSeries('input[name="qpcr_result_replicate_count[]"]', normalized.map(row => row.replicateCount));
  fillSelectSeries('select[name="qpcr_result_is_outlier[]"]', normalized.map(row => row.outlier));
  fillInputSeries('input[name="qpcr_result_note[]"]', normalized.map(row => row.note));
  refreshQpcrChart();
  return outcome;
};

const parseElisaImport = (rows: SmartlabRow[]): SmartlabImportOutcome<SmartlabElisaImportRow> => {
  const headers = new Set(rows.flatMap(row => Object.keys(row)));
  const parserLabel = (
    headers.has('mean_abs') || headers.has('mean_od') || headers.has('sample_id')
      ? 'ELISA 读板结果表'
      : '通用 ELISA 结果表'
  );

  const normalized = rows.map((row) => ({
    sample: pick(row, ['sample', 'sample_label', 'sample_id', 'sample_name', 'unknown', 'unknowns', 'name', 'well_sample']),
    group: pick(row, ['group', 'group_label', 'condition']),
    od450: pick(row, ['od450', 'od_450', 'mean_od450', 'mean_od', 'mean_abs', 'od', 'absorbance', 'abs']),
    concentration: pick(row, ['concentration', 'conc', 'calculated_concentration', 'result', 'pg_ml', 'ng_ml']),
    cvPercent: pick(row, ['cv_percent', 'cv', 'cv_pct', 'replicate_cv']),
    note: pick(row, ['note', 'comment', 'remarks', 'well']),
  })).filter((row) => {
    const sampleToken = normalizeToken(row.sample);
    if (!row.sample && !row.od450 && !row.concentration) {
      return false;
    }
    if (['blank', 'std', 'standard'].includes(sampleToken)) {
      return false;
    }
    return true;
  });

  if (!normalized.length) {
    throw new Error('未识别到 ELISA 结果列。请确认文件包含 sample / od450 / concentration / cv_percent 等列。');
  }

  return {
    rows: normalized,
    parserLabel,
    detail: parserLabel === 'ELISA 读板结果表'
      ? '已识别读板仪导出列名，并回填 OD450、浓度和 CV。'
      : '已按表头直接映射到 ELISA 结构化结果表。',
  };
};

const importElisaRows = (rows: SmartlabRow[]): SmartlabImportOutcome<SmartlabElisaImportRow> => {
  const outcome = parseElisaImport(rows);
  const normalized = outcome.rows;
  if (!normalized.length) {
    throw new Error('未识别到 ELISA 结果列。请确认文件包含 sample / od450 / concentration / cv_percent 等列。');
  }

  fillInputSeries('input[name="elisa_result_sample_label[]"]', normalized.map(row => row.sample));
  fillInputSeries('input[name="elisa_result_group_label[]"]', normalized.map(row => row.group));
  fillInputSeries('input[name="elisa_result_od450[]"]', normalized.map(row => row.od450));
  fillInputSeries('input[name="elisa_result_concentration[]"]', normalized.map(row => row.concentration));
  fillInputSeries('input[name="elisa_result_cv_percent[]"]', normalized.map(row => row.cvPercent));
  fillInputSeries('input[name="elisa_result_note[]"]', normalized.map(row => row.note));
  refreshElisaChart();
  return outcome;
};

const mode = new URLSearchParams(window.location.search).get('mode');
if (mode === 'view') {
  // UPDATE MALLEABLE COMMENT
  const malleableComments = new Malle({
    cancel : i18next.t('cancel'),
    cancelClasses: ['button', 'btn', 'btn-danger', 'mt-2', 'ml-1'],
    inputClasses: ['form-control'],
    fun: async (value, original) => {
      const resp = await ApiC.patch(`${entity.type}/${entity.id}/${Model.Comment}/${original.dataset.id}`, {'comment': value});
      const json = await resp.json();
      // we reload all so the edition date is also reloaded
      reloadElements(['commentsDiv']).then(() => {
        malleableComments.listen();
      });
      return json.comment;
    },
    inputType: InputType.Textarea,
    listenOn: '.comment.editable',
    returnedValueIsTrustedHtml: false,
    submit : i18next.t('save'),
    submitClasses: ['button', 'btn', 'btn-primary', 'mt-2'],
    tooltip: i18next.t('click-to-edit'),
  });

  on('create-comment', () => {
    const content = (document.getElementById('commentsCreateArea') as HTMLTextAreaElement).value;
    ApiC.post(`${entity.type}/${entity.id}/${Model.Comment}`, {comment: content}).then(() => {
      reloadElements(['commentsDiv']).then(() => {
        malleableComments.listen();
      });
    });
  });

  on('destroy-comment', (el: HTMLElement) => {
    if (confirm(i18next.t('generic-delete-warning'))) {
      ApiC.delete(`${entity.type}/${entity.id}/${Model.Comment}/${el.dataset.id}`).then(() => el.parentElement.parentElement.remove());
    }
  });

  on('override-exclusive-edit-lock', () => {
    ApiC.patch(`${entity.type}/${entity.id}`, { action: Action.RemoveExclusiveEditMode })
      .then(() => window.location.href = `?mode=view&id=${entity.id}`);
  });

  // add the title in the page name (see #324)
  const titleElement = document.getElementById('documentTitle');
  document.title = titleElement?.textContent ? `${titleElement.textContent} - eLabFTW` : 'eLabFTW';

  if (!core.isAnon) {
    // listen on existing comments
    malleableComments.listen();
  }

  const qpcrInputs = document.querySelectorAll('input[name="qpcr_result_sample_label[]"], input[name="qpcr_result_fold_change[]"]');
  qpcrInputs.forEach((input) => {
    input.addEventListener('input', refreshQpcrChart);
    input.addEventListener('change', refreshQpcrChart);
  });
  refreshQpcrChart();

  const elisaInputs = document.querySelectorAll('input[name="elisa_result_sample_label[]"], input[name="elisa_result_concentration[]"]');
  elisaInputs.forEach((input) => {
    input.addEventListener('input', refreshElisaChart);
    input.addEventListener('change', refreshElisaChart);
  });
  refreshElisaChart();

  const wbInputs = document.querySelectorAll('input[name="wb_result_sample_label[]"], input[name="wb_result_normalized_signal[]"]');
  wbInputs.forEach((input) => {
    input.addEventListener('input', refreshWbChart);
    input.addEventListener('change', refreshWbChart);
  });
  refreshWbChart();

  const ifInputs = document.querySelectorAll('input[name="if_result_field_label[]"], input[name="if_result_mean_intensity[]"]');
  ifInputs.forEach((input) => {
    input.addEventListener('input', refreshIfChart);
    input.addEventListener('change', refreshIfChart);
  });
  refreshIfChart();

  const ihcInputs = document.querySelectorAll('input[name="ihc_result_region_label[]"], input[name="ihc_result_h_score[]"]');
  ihcInputs.forEach((input) => {
    input.addEventListener('input', refreshIhcChart);
    input.addEventListener('change', refreshIhcChart);
  });
  refreshIhcChart();

  document.querySelectorAll('[data-smartlab-import]').forEach((input) => {
    input.addEventListener('change', async (event) => {
      const target = event.currentTarget as HTMLInputElement;
      const file = target.files?.[0];
      if (!file) {
        return;
      }

      try {
        const aoa = await fileToAOA(file);
        const rows = aoaToRows(aoa);
        let outcome: SmartlabImportOutcome<SmartlabQpcrImportRow | SmartlabElisaImportRow> | null = null;
        if (target.dataset.smartlabImport === 'qpcr') {
          outcome = importQpcrRows(rows);
          setImportStatus('qpcr', `已识别 ${outcome.parserLabel}，导入 ${outcome.rows.length} 条结果。${outcome.detail}`, 'success');
        } else if (target.dataset.smartlabImport === 'elisa') {
          outcome = importElisaRows(rows);
          setImportStatus('elisa', `已识别 ${outcome.parserLabel}，导入 ${outcome.rows.length} 条结果。${outcome.detail}`, 'success');
        }
        notify.success(`${outcome?.parserLabel || '结果文件'}导入完成`);
      } catch (error) {
        const message = error instanceof Error ? error.message : '导入失败。';
        if (target.dataset.smartlabImport === 'qpcr' || target.dataset.smartlabImport === 'elisa') {
          setImportStatus(target.dataset.smartlabImport, message, 'danger');
        }
        notify.error(message);
      } finally {
        target.value = '';
      }
    });
  });

  const wbImageInput = document.getElementById('wb-analysis-file') as HTMLInputElement | null;
  if (wbImageInput) {
    wbImageInput.addEventListener('change', async (event) => {
      const target = event.currentTarget as HTMLInputElement;
      const file = target.files?.[0];
      if (!file) {
        return;
      }

      try {
        await analyzeWesternBlotImage(file);
        notify.success('WB 图像分析完成');
      } catch (error) {
        const message = error instanceof Error ? error.message : 'WB 图像分析失败。';
        setWbAnalysisStatus(message, 'danger');
        notify.error(message);
      } finally {
        target.value = '';
      }
    });
  }

  const ifImageInput = document.getElementById('if-analysis-file') as HTMLInputElement | null;
  if (ifImageInput) {
    ifImageInput.addEventListener('change', async (event) => {
      const target = event.currentTarget as HTMLInputElement;
      const file = target.files?.[0];
      if (!file) {
        return;
      }

      try {
        await analyzeIfImage(file);
        notify.success('IF 图像分析完成');
      } catch (error) {
        const message = error instanceof Error ? error.message : 'IF 图像分析失败。';
        setImageStatus('if', message, 'danger');
        notify.error(message);
      } finally {
        target.value = '';
      }
    });
  }

  const ihcImageInput = document.getElementById('ihc-analysis-file') as HTMLInputElement | null;
  if (ihcImageInput) {
    ihcImageInput.addEventListener('change', async (event) => {
      const target = event.currentTarget as HTMLInputElement;
      const file = target.files?.[0];
      if (!file) {
        return;
      }

      try {
        await analyzeIhcImage(file);
        notify.success('IHC 图像分析完成');
      } catch (error) {
        const message = error instanceof Error ? error.message : 'IHC 图像分析失败。';
        setImageStatus('ihc', message, 'danger');
        notify.error(message);
      } finally {
        target.value = '';
      }
    });
  }

  const bindImageRoiCanvas = (kind: 'if' | 'ihc', rerender: () => void): void => {
    const canvas = document.getElementById(`smartlab-${kind}-preview`) as HTMLCanvasElement | null;
    if (!canvas) {
      return;
    }
    const getState = (): SmartlabIfState | SmartlabIhcState | null => (kind === 'if' ? ifState : ihcState);
    const resolveMode = (event: MouseEvent): 'move' | 'resize' | null => {
      const state = getState();
      if (!state) {
        return null;
      }
      const rect = canvas.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width;
      const y = (event.clientY - rect.top) / rect.height;
      const roi = state.roi;
      const inBox = x >= roi.x && x <= roi.x + roi.w && y >= roi.y && y <= roi.y + roi.h;
      if (!inBox) {
        return null;
      }
      const nearHandle = Math.abs(x - (roi.x + roi.w)) < 0.04 && Math.abs(y - (roi.y + roi.h)) < 0.04;
      return nearHandle ? 'resize' : 'move';
    };

    canvas.addEventListener('mousedown', (event) => {
      const state = getState();
      const mode = resolveMode(event);
      if (!state || !mode) {
        return;
      }
      const rect = canvas.getBoundingClientRect();
      imageRoiDrag = {
        kind,
        mode,
        startX: (event.clientX - rect.left) / rect.width,
        startY: (event.clientY - rect.top) / rect.height,
        roi: { ...state.roi },
      };
    });

    canvas.addEventListener('mousemove', (event) => {
      const state = getState();
      if (!state) {
        return;
      }
      const hoverMode = resolveMode(event);
      canvas.style.cursor = hoverMode === 'resize' ? 'nwse-resize' : hoverMode === 'move' ? 'move' : 'default';
      if (!imageRoiDrag || imageRoiDrag.kind !== kind) {
        return;
      }
      const rect = canvas.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width;
      const y = (event.clientY - rect.top) / rect.height;
      const dx = x - imageRoiDrag.startX;
      const dy = y - imageRoiDrag.startY;
      if (imageRoiDrag.mode === 'move') {
        state.roi = clampRoi({
          x: imageRoiDrag.roi.x + dx,
          y: imageRoiDrag.roi.y + dy,
          w: imageRoiDrag.roi.w,
          h: imageRoiDrag.roi.h,
        });
      } else {
        state.roi = clampRoi({
          x: imageRoiDrag.roi.x,
          y: imageRoiDrag.roi.y,
          w: imageRoiDrag.roi.w + dx,
          h: imageRoiDrag.roi.h + dy,
        });
      }
      rerender();
    });

    canvas.addEventListener('mouseleave', () => {
      if (!imageRoiDrag) {
        canvas.style.cursor = 'default';
      }
    });
  };

  bindImageRoiCanvas('if', rerenderIfAnalysis);
  bindImageRoiCanvas('ihc', rerenderIhcAnalysis);
  window.addEventListener('mouseup', () => {
    if (imageRoiDrag) {
      setImageStatus(imageRoiDrag.kind, '已根据 ROI 重算图像指标。', 'success');
    }
    imageRoiDrag = null;
  });

  ['if-analysis-threshold', 'if-analysis-nuclear-threshold', 'if-analysis-field-label'].forEach((id) => {
    const input = document.getElementById(id) as HTMLInputElement | null;
    if (!input) {
      return;
    }
    input.addEventListener('change', () => {
      if (ifState) {
        rerenderIfAnalysis();
      }
    });
  });

  ['ihc-analysis-threshold', 'ihc-analysis-region-label'].forEach((id) => {
    const input = document.getElementById(id) as HTMLInputElement | null;
    if (!input) {
      return;
    }
    input.addEventListener('change', () => {
      if (ihcState) {
        rerenderIhcAnalysis();
      }
    });
  });

  const wbExportButton = document.getElementById('wb-analysis-export');
  if (wbExportButton) {
    wbExportButton.addEventListener('click', async () => {
      try {
        await exportWbPreview();
        notify.success('WB 标注图已导出');
      } catch (error) {
        notify.error(error instanceof Error ? error.message : '导出失败。');
      }
    });
  }

  const wbPreviewCanvas = document.getElementById('smartlab-wb-preview') as HTMLCanvasElement | null;
  if (wbPreviewCanvas) {
    const resolveWbHandle = (event: MouseEvent): { type: 'divider' | 'target_center' | 'target_top' | 'target_bottom' | 'reference_center' | 'reference_top' | 'reference_bottom'; index?: number } | null => {
      if (!wbState) {
        return null;
      }
      const rect = wbPreviewCanvas.getBoundingClientRect();
      const xRatio = clamp((event.clientX - rect.left) / rect.width, 0, 1);
      const yRatio = clamp((event.clientY - rect.top) / rect.height, 0, 1);
      const threshold = 0.03;
      const dividerIndex = wbState.laneDividers.findIndex(divider => Math.abs(divider - xRatio) < threshold);
      if (dividerIndex >= 0) {
        return { type: 'divider', index: dividerIndex };
      }
      const resolveBandHandle = (
        centerRatio: number,
        heightRatio: number,
        prefix: 'target' | 'reference',
      ): { type: 'target_center' | 'target_top' | 'target_bottom' | 'reference_center' | 'reference_top' | 'reference_bottom' } | null => {
        const top = centerRatio - (heightRatio / 2);
        const bottom = centerRatio + (heightRatio / 2);
        if (Math.abs(top - yRatio) < threshold) {
          return { type: `${prefix}_top` as 'target_top' | 'reference_top' };
        }
        if (Math.abs(centerRatio - yRatio) < threshold) {
          return { type: `${prefix}_center` as 'target_center' | 'reference_center' };
        }
        if (Math.abs(bottom - yRatio) < threshold) {
          return { type: `${prefix}_bottom` as 'target_bottom' | 'reference_bottom' };
        }
        return null;
      };
      return resolveBandHandle(wbState.targetY, wbState.targetHeightRatio, 'target')
        || resolveBandHandle(wbState.referenceY, wbState.referenceHeightRatio, 'reference');
    };

    wbPreviewCanvas.addEventListener('mousedown', (event) => {
      wbDragHandle = resolveWbHandle(event);
    });

    wbPreviewCanvas.addEventListener('mousemove', (event) => {
      if (!wbState) {
        return;
      }
      const activeHandle = wbDragHandle || resolveWbHandle(event);
      wbPreviewCanvas.style.cursor = activeHandle ? 'grab' : 'default';
      if (!wbDragHandle) {
        return;
      }

      const rect = wbPreviewCanvas.getBoundingClientRect();
      const xRatio = clamp((event.clientX - rect.left) / rect.width, 0.05, 0.95);
      const yRatio = clamp((event.clientY - rect.top) / rect.height, 0.05, 0.95);
      if (wbDragHandle.type === 'divider' && wbDragHandle.index !== undefined) {
        const previous = wbDragHandle.index === 0 ? 0.05 : wbState.laneDividers[wbDragHandle.index - 1] + 0.03;
        const next = wbDragHandle.index === wbState.laneDividers.length - 1 ? 0.95 : wbState.laneDividers[wbDragHandle.index + 1] - 0.03;
        wbState.laneDividers[wbDragHandle.index] = clamp(xRatio, previous, next);
      }
      const minHeight = 0.02;
      if (wbDragHandle.type === 'target_center') {
        wbState.targetY = clamp(yRatio, 0.05 + (wbState.targetHeightRatio / 2), wbState.referenceY - 0.03);
      }
      if (wbDragHandle.type === 'reference_center') {
        wbState.referenceY = clamp(yRatio, wbState.targetY + 0.03, 0.95 - (wbState.referenceHeightRatio / 2));
      }
      if (wbDragHandle.type === 'target_top') {
        const bottom = wbState.targetY + (wbState.targetHeightRatio / 2);
        const nextTop = clamp(yRatio, 0.05, bottom - minHeight);
        wbState.targetHeightRatio = clamp(bottom - nextTop, minHeight, 0.4);
        wbState.targetY = nextTop + (wbState.targetHeightRatio / 2);
      }
      if (wbDragHandle.type === 'target_bottom') {
        const top = wbState.targetY - (wbState.targetHeightRatio / 2);
        const nextBottom = clamp(yRatio, top + minHeight, wbState.referenceY - 0.02);
        wbState.targetHeightRatio = clamp(nextBottom - top, minHeight, 0.4);
        wbState.targetY = top + (wbState.targetHeightRatio / 2);
      }
      if (wbDragHandle.type === 'reference_top') {
        const bottom = wbState.referenceY + (wbState.referenceHeightRatio / 2);
        const nextTop = clamp(yRatio, wbState.targetY + 0.02, bottom - minHeight);
        wbState.referenceHeightRatio = clamp(bottom - nextTop, minHeight, 0.4);
        wbState.referenceY = nextTop + (wbState.referenceHeightRatio / 2);
      }
      if (wbDragHandle.type === 'reference_bottom') {
        const top = wbState.referenceY - (wbState.referenceHeightRatio / 2);
        const nextBottom = clamp(yRatio, top + minHeight, 0.95);
        wbState.referenceHeightRatio = clamp(nextBottom - top, minHeight, 0.4);
        wbState.referenceY = top + (wbState.referenceHeightRatio / 2);
      }
      rerenderWbState();
    });

    const releaseWbDrag = (): void => {
      if (wbDragHandle) {
        setWbAnalysisStatus('已根据拖拽位置重算 WB 灰度结果。', 'success');
      }
      wbDragHandle = null;
      if (wbPreviewCanvas) {
        wbPreviewCanvas.style.cursor = 'default';
      }
    };
    window.addEventListener('mouseup', releaseWbDrag);
    wbPreviewCanvas.addEventListener('mouseleave', () => {
      if (!wbDragHandle) {
        wbPreviewCanvas.style.cursor = 'default';
      }
    });
  }

  ['wb-analysis-lanes', 'wb-analysis-target-band', 'wb-analysis-reference-band', 'wb-analysis-band-window', 'wb-analysis-invert', 'wb-analysis-control-lane'].forEach((id) => {
    const input = document.getElementById(id) as HTMLInputElement | null;
    if (!input) {
      return;
    }
    input.addEventListener('change', () => {
      if (!wbState) {
        return;
      }
      if (id === 'wb-analysis-lanes') {
        const laneCount = clamp(parseInt(input.value || '4', 10) || 4, 1, 24);
        wbState.laneDividers = Array.from({ length: laneCount - 1 }, (_, index) => (index + 1) / laneCount);
      } else if (id === 'wb-analysis-target-band') {
        wbState.targetY = clamp((parseFloat(input.value || '35') || 35) / 100, 0.05 + (wbState.targetHeightRatio / 2), wbState.referenceY - 0.03);
      } else if (id === 'wb-analysis-reference-band') {
        wbState.referenceY = clamp((parseFloat(input.value || '75') || 75) / 100, wbState.targetY + 0.03, 0.95 - (wbState.referenceHeightRatio / 2));
      } else if (id === 'wb-analysis-band-window') {
        const sharedHeight = clamp((parseFloat(input.value || '8') || 8) / 100, 0.02, 0.25);
        wbState.targetHeightRatio = sharedHeight;
        wbState.referenceHeightRatio = sharedHeight;
      } else if (id === 'wb-analysis-invert') {
        wbState.invert = input.checked;
      } else if (id === 'wb-analysis-control-lane') {
        wbState.controlLaneIndex = clamp(parseInt(input.value || '0', 10) || 0, 0, Math.max(getWbLaneCount() - 1, 0));
      }
      rerenderWbState();
    });
  });
}
