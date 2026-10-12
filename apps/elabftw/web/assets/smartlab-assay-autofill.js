(function () {
  function byId(id) {
    return document.getElementById(id);
  }

  function initSmartlabAssayAutofill() {
    var sampleSelect = byId('assay-linked-sample-id');
    var lotSelect = byId('assay-linked-lot-id');
    var sampleHint = byId('assay-linked-sample-hint');
    var lotHint = byId('assay-linked-lot-hint');
    var sampleSourceInput = byId('assay-sample-source');
    var qpcrSampleOriginInput = byId('qpcr-sample-origin');
    var wbSampleOriginInput = byId('wb-sample-origin');
    var ifSampleOriginInput = byId('if-sample-origin');
    var ihcTissueIdInput = byId('ihc-tissue-id');
    var qpcrKitNameInput = byId('qpcr-kit-name');
    var wbDetectionReagentInput = byId('wb-detection-reagent');
    var usageSourceSelect = byId('smartlab-usage-source');
    var usageLotSelect = byId('smartlab-usage-lot');
    var usageFilterInput = byId('smartlab-usage-filter');
    var usageInventoryEmpty = byId('smartlab-usage-inventory-empty');
    var usageMaterialInput = byId('smartlab-usage-material');
    var usageBrandInput = byId('smartlab-usage-brand');
    var usageCatalogInput = byId('smartlab-usage-catalog');
    var usageLotNoInput = byId('smartlab-usage-lot-no');
    var usageLocationInput = byId('smartlab-usage-location');
    var usageUnitInput = byId('smartlab-usage-unit');

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

    function updateObjectSelect(select) {
      var option = select.options[select.selectedIndex];
      var hint = document.querySelector('[data-object-hint="' + select.id + '"]');
      if (!option || option.value === '0') {
        if (hint) {
          hint.textContent = '请从助理端库存或对象字典选择；需补充说明可在下方试剂与仪器记录中填写。';
        }
        return;
      }
      (select.dataset.targets || '').split(',').forEach(function (pair) {
        var parts = pair.split(':');
        if (parts.length !== 2) {
          return;
        }
        setFieldValue(byId(parts[0]), option.dataset[parts[1]] || '');
      });
      if (hint) {
        hint.textContent = option.dataset.summary || option.textContent.trim();
      }
    }

    function updateSampleLink() {
      if (!sampleSelect || !sampleHint) {
        return;
      }
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
      if (code || name) {
        parts.push([code, name].filter(Boolean).join(' · '));
      }
      if (type) {
        parts.push(type);
      }
      if (project) {
        parts.push('项目 ' + project);
      }
      if (quantity) {
        parts.push('当前 ' + quantity + (unit ? ' ' + unit : ''));
      }
      sampleHint.textContent = parts.join(' · ');

      var sampleSummary = [code, name, type].filter(Boolean).join(' · ');
      setIfBlank(sampleSourceInput, sampleSummary);
      setIfBlank(qpcrSampleOriginInput, sampleSummary);
      setIfBlank(wbSampleOriginInput, sampleSummary);
      setIfBlank(ifSampleOriginInput, sampleSummary);
      setIfBlank(ihcTissueIdInput, code || name);
    }

    function updateLotLink() {
      if (!lotSelect || !lotHint) {
        return;
      }
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
      if (materialCode || materialName) {
        parts.push([materialCode, materialName].filter(Boolean).join(' · '));
      }
      if (lotNo) {
        parts.push('批号 ' + lotNo);
      }
      if (quantity) {
        parts.push('库存 ' + quantity + (unit ? ' ' + unit : ''));
      }
      if (expires) {
        parts.push('到期 ' + expires);
      }
      lotHint.textContent = parts.join(' · ');

      var materialSummary = [materialCode, materialName].filter(Boolean).join(' · ');
      setIfBlank(qpcrKitNameInput, materialSummary);
      setIfBlank(wbDetectionReagentInput, materialSummary + (lotNo ? ' / ' + lotNo : ''));
    }

    function setReadonly(input, readonly) {
      if (input) {
        input.readOnly = readonly;
      }
    }

    function updateUsageReadonly() {
      var readonly = !usageSourceSelect || usageSourceSelect.value !== 'manual';
      [usageMaterialInput, usageBrandInput, usageCatalogInput, usageLotNoInput, usageLocationInput, usageUnitInput].forEach(function (input) {
        setReadonly(input, readonly);
      });
    }

    function updateUsageFromInventory() {
      if (!usageLotSelect) {
        return;
      }
      var selected = Array.prototype.filter.call(usageLotSelect.options, function (option) {
        return option.selected && option.value !== '';
      });
      var option = selected[0];
      if (!option) {
        return;
      }
      if (usageMaterialInput) {
        usageMaterialInput.value = option.dataset.materialLabel || option.textContent.trim();
      }
      if (usageBrandInput) {
        usageBrandInput.value = option.dataset.brand || '';
      }
      if (usageCatalogInput) {
        usageCatalogInput.value = option.dataset.catalogNo || '';
      }
      if (usageLotNoInput) {
        usageLotNoInput.value = option.dataset.lotNo || '';
      }
      if (usageLocationInput) {
        usageLocationInput.value = option.dataset.locationLabel || '';
      }
      if (usageUnitInput) {
        usageUnitInput.value = option.dataset.unit || '';
      }
    }

    function filterUsageInventory() {
      if (!usageLotSelect || !usageFilterInput) {
        return;
      }
      var query = usageFilterInput.value.trim().toLowerCase();
      var visible = 0;
      Array.prototype.forEach.call(usageLotSelect.options, function (option) {
        if (option.disabled) {
          return;
        }
        var matched = query === '' || (option.dataset.search || '').indexOf(query) !== -1;
        option.hidden = !matched;
        if (matched) {
          visible++;
        }
      });
      if (usageInventoryEmpty) {
        usageInventoryEmpty.classList.toggle('d-none', visible > 0);
      }
    }

    if (sampleSelect) {
      sampleSelect.addEventListener('change', updateSampleLink);
      updateSampleLink();
    }
    if (lotSelect) {
      lotSelect.addEventListener('change', updateLotLink);
      updateLotLink();
    }
    document.querySelectorAll('.smartlab-object-select').forEach(function (select) {
      select.addEventListener('change', function () {
        updateObjectSelect(select);
      });
      updateObjectSelect(select);
    });
    if (usageSourceSelect) {
      usageSourceSelect.addEventListener('change', updateUsageReadonly);
      updateUsageReadonly();
    }
    if (usageLotSelect) {
      usageLotSelect.addEventListener('change', updateUsageFromInventory);
      updateUsageFromInventory();
    }
    if (usageFilterInput) {
      usageFilterInput.addEventListener('input', filterUsageInventory);
      filterUsageInventory();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSmartlabAssayAutofill);
  } else {
    initSmartlabAssayAutofill();
  }
})();
