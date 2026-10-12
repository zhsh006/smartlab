/**
 * SmartLab Usage — merged assay-autofill + usage-record.
 *
 * Replaces the two previously-conflicting scripts:
 *   smartlab-assay-autofill.js  (sample/lot linking, object select, readonly toggle)
 *   smartlab-usage-record.js    (multi-select inventory lot fill, source toggle)
 */
(function () {
  'use strict';

  function byId(id) {
    return document.getElementById(id);
  }

  // ---- helpers ----
  function setIfBlank(input, value) {
    value = value || '';
    if (!input || input.value.trim() !== '' || value.trim() === '') {
      return;
    }
    input.value = value;
  }

  function setFieldValue(input, value) {
    value = value || '';
    if (!input || value.trim() === '') {
      return;
    }
    if (input.tagName === 'SELECT') {
      var exists = Array.prototype.some.call(input.options, function (option) {
        return option.value === value;
      });
      if (!exists) {
        var option = document.createElement('option');
        option.value = value;
        option.textContent = value + '（对象回填）';
        input.appendChild(option);
      }
    }
    input.value = value;
  }

  function setValue(input, value) {
    if (!input) { return; }
    input.value = value || '';
  }

  function mapUsageType(category) {
    switch ((category || '').toLowerCase()) {
      case 'antibody': case '抗体': return 'antibody';
      case 'primer':   case '引物': return 'primer';
      case 'kit':      case '试剂盒': return 'kit';
      case 'consumable': case '耗材': return 'consumable';
      case 'sample':   case '样本': return 'sample';
      case 'instrument': case '仪器': return 'instrument';
      case 'other':    case 'key_material': case '其他': return 'other';
      case 'reagent':  case '试剂': return 'reagent';
      default: return '';
    }
  }

  function clearInventoryFields() {
    setValue(byId('smartlab-usage-material'), '');
    setValue(byId('smartlab-usage-brand'), '');
    setValue(byId('smartlab-usage-catalog'), '');
    setValue(byId('smartlab-usage-lot-no'), '');
    setValue(byId('smartlab-usage-location'), '');
    setValue(byId('smartlab-usage-unit'), '');
    setValue(byId('smartlab-usage-type'), 'reagent');
  }

  // ---- Assay autofill: sample/lot link ----
  function updateSampleLink() {
    var sampleSelect = byId('assay-linked-sample-id');
    var sampleHint = byId('assay-linked-sample-hint');
    if (!sampleSelect || !sampleHint) { return; }

    var option = sampleSelect.options[sampleSelect.selectedIndex];
    if (!option || option.value === '0') {
      sampleHint.textContent = '选择后会显示样本类型、项目和数量提示，并可回填来源字段。';
      return;
    }

    var code = option.dataset.sampleCode || '';
    var name = option.dataset.sampleName || '';
    var type = option.dataset.sampleType || '';
    var project = option.dataset.sampleProject || '';
    var quantity = option.dataset.sampleQuantity || '';
    var unit = option.dataset.sampleUnit || '';
    var parts = [];
    if (code || name) { parts.push([code, name].filter(Boolean).join(' · ')); }
    if (type) { parts.push(type); }
    if (project) { parts.push('项目 ' + project); }
    if (quantity) { parts.push('当前 ' + quantity + (unit ? ' ' + unit : '')); }
    sampleHint.textContent = parts.join(' · ');

    var sampleSummary = [code, name, type].filter(Boolean).join(' · ');
    setIfBlank(byId('assay-sample-source'), sampleSummary);
    setIfBlank(byId('qpcr-sample-origin'), sampleSummary);
    setIfBlank(byId('wb-sample-origin'), sampleSummary);
    setIfBlank(byId('if-sample-origin'), sampleSummary);
    setIfBlank(byId('ihc-tissue-id'), code || name);
  }

  function updateLotLink() {
    var lotSelect = byId('assay-linked-lot-id');
    var lotHint = byId('assay-linked-lot-hint');
    if (!lotSelect || !lotHint) { return; }

    var option = lotSelect.options[lotSelect.selectedIndex];
    if (!option || option.value === '0') {
      lotHint.textContent = '选择后会显示库存物料和到期日提示，便于录入试剂信息。';
      return;
    }

    var materialCode = option.dataset.materialCode || '';
    var materialName = option.dataset.materialName || '';
    var lotNo = option.dataset.lotNo || '';
    var quantity = option.dataset.lotQuantity || '';
    var unit = option.dataset.lotUnit || '';
    var expires = option.dataset.lotExpires || '';
    var parts = [];
    if (materialCode || materialName) { parts.push([materialCode, materialName].filter(Boolean).join(' · ')); }
    if (lotNo) { parts.push('批号 ' + lotNo); }
    if (quantity) { parts.push('库存 ' + quantity + (unit ? ' ' + unit : '')); }
    if (expires) { parts.push('到期 ' + expires); }
    lotHint.textContent = parts.join(' · ');

    var materialSummary = [materialCode, materialName].filter(Boolean).join(' · ');
    setIfBlank(byId('qpcr-kit-name'), materialSummary);
    setIfBlank(byId('wb-detection-reagent'), materialSummary + (lotNo ? ' / ' + lotNo : ''));
  }

  // ---- Object select autofill ----
  function updateObjectSelect(select) {
    var option = select.options[select.selectedIndex];
    var hint = document.querySelector('[data-object-hint="' + select.id + '"]');
    if (!option || option.value === '0') {
      if (hint) { hint.textContent = '请从助理端库存或对象字典选择；需补充说明可在下方试剂与仪器记录中填写。'; }
      return;
    }
    (select.dataset.targets || '').split(',').forEach(function (pair) {
      var parts = pair.split(':');
      if (parts.length !== 2) { return; }
      setFieldValue(byId(parts[0]), option.dataset[parts[1]] || '');
    });
    if (hint) { hint.textContent = option.dataset.summary || option.textContent.trim(); }
  }

  // ---- Usage record: inventory lot fill ----
  function selectedInventoryOptions(select) {
    return Array.from(select.selectedOptions).filter(function (option) {
      return option.value !== '';
    });
  }

  function fillFromOption(option) {
    if (!option || !option.value) { return; }
    setValue(byId('smartlab-usage-material'), option.dataset.materialLabel || option.textContent.trim());
    setValue(byId('smartlab-usage-brand'), option.dataset.brand || '');
    setValue(byId('smartlab-usage-catalog'), option.dataset.catalogNo || '');
    setValue(byId('smartlab-usage-lot-no'), option.dataset.lotNo || '');
    setValue(byId('smartlab-usage-location'), option.dataset.locationLabel || '');
    setValue(byId('smartlab-usage-unit'), option.dataset.unit || '');

    var source = byId('smartlab-usage-source');
    if (source) { source.value = 'inventory'; }

    var usageType = byId('smartlab-usage-type');
    var mapped = mapUsageType(option.dataset.category || '');
    if (usageType && mapped) { usageType.value = mapped; }
  }

  function fillFromSelection(select) {
    var selected = selectedInventoryOptions(select);
    if (selected.length === 0) {
      var source = byId('smartlab-usage-source');
      if (source && source.value === 'inventory') { clearInventoryFields(); }
      return;
    }

    var source = byId('smartlab-usage-source');
    if (source) { source.value = 'inventory'; }

    if (selected.length === 1) {
      fillFromOption(selected[0]);
      return;
    }

    setValue(byId('smartlab-usage-material'), '已选择 ' + selected.length + ' 个库存物料');
    setValue(byId('smartlab-usage-brand'), '');
    setValue(byId('smartlab-usage-catalog'), '');
    setValue(byId('smartlab-usage-lot-no'), '');
    setValue(byId('smartlab-usage-location'), '');
    setValue(byId('smartlab-usage-unit'), '');

    var usageType = byId('smartlab-usage-type');
    var selectedTypes = Array.from(new Set(selected.map(function (option) {
      return mapUsageType(option.dataset.category || '');
    }).filter(Boolean)));
    if (usageType) {
      usageType.value = selectedTypes.length === 1 ? selectedTypes[0] : 'other';
    }
  }

  function matches(option, query) {
    if (!query) { return true; }
    return ((option.dataset.search || '') + ' ' + option.textContent).toLowerCase().includes(query);
  }

  function updateUsageReadonly() {
    var source = byId('smartlab-usage-source');
    var readonly = !source || source.value !== 'manual';
    ['smartlab-usage-material', 'smartlab-usage-brand', 'smartlab-usage-catalog',
     'smartlab-usage-lot-no', 'smartlab-usage-location', 'smartlab-usage-unit'].forEach(function (id) {
      var el = byId(id);
      if (el) { el.readOnly = readonly; }
    });
  }

  // ---- Init ----
  function init() {
    // --- Assay sample/lot linking ---
    var sampleSelect = byId('assay-linked-sample-id');
    if (sampleSelect) {
      sampleSelect.addEventListener('change', updateSampleLink);
      updateSampleLink();
    }
    var lotSelect = byId('assay-linked-lot-id');
    if (lotSelect) {
      lotSelect.addEventListener('change', updateLotLink);
      updateLotLink();
    }
    document.querySelectorAll('.smartlab-object-select').forEach(function (select) {
      select.addEventListener('change', function () { updateObjectSelect(select); });
      updateObjectSelect(select);
    });

    // --- Usage record inventory fill ---
    var usageLot = byId('smartlab-usage-lot');
    var usageFilter = byId('smartlab-usage-filter');
    var usageEmpty = byId('smartlab-usage-inventory-empty');

    if (usageLot) {
      usageLot.disabled = false;

      usageLot.addEventListener('change', function () {
        fillFromSelection(usageLot);
      });

      if (usageFilter) {
        usageFilter.addEventListener('input', function () {
          var query = usageFilter.value.trim().toLowerCase();
          var visibleCount = 0;
          Array.from(usageLot.options).forEach(function (option) {
            if (!option.value) { option.hidden = false; return; }
            var isMatch = matches(option, query);
            option.hidden = query !== '' && !isMatch && !option.selected;
            if (!option.hidden) { visibleCount += 1; }
          });
          if (usageEmpty) { usageEmpty.classList.toggle('d-none', visibleCount > 0 || query === ''); }
        });
      }

      // Run initial fill
      fillFromSelection(usageLot);
    }

    // --- Source toggle (readonly toggle) ---
    var source = byId('smartlab-usage-source');
    if (source) {
      source.addEventListener('change', function () {
        updateUsageReadonly();
        if (source.value === 'manual') {
          if (usageLot) {
            Array.from(usageLot.options).forEach(function (o) { o.selected = false; });
          }
          clearInventoryFields();
        }
      });
      updateUsageReadonly();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
