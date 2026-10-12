(function () {
  'use strict';

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
      return;
    }
    callback();
  }

  function byId(id) {
    return document.getElementById(id);
  }

  function setValue(input, value) {
    if (!input) {
      return;
    }
    input.value = value || '';
  }

  function fillFromOption(option) {
    if (!option || !option.value) {
      return;
    }

    setValue(byId('smartlab-usage-material'), option.dataset.materialLabel || option.textContent.trim());
    setValue(byId('smartlab-usage-brand'), option.dataset.brand || '');
    setValue(byId('smartlab-usage-catalog'), option.dataset.catalogNo || '');
    setValue(byId('smartlab-usage-lot-no'), option.dataset.lotNo || '');
    setValue(byId('smartlab-usage-location'), option.dataset.locationLabel || '');
    setValue(byId('smartlab-usage-unit'), option.dataset.unit || '');

    const source = byId('smartlab-usage-source');
    if (source) {
      source.value = 'inventory';
    }

    const usageType = byId('smartlab-usage-type');
    const mappedUsageType = mapUsageType(option.dataset.category || '');
    if (usageType && mappedUsageType) {
      usageType.value = mappedUsageType;
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

  function selectedInventoryOptions(select) {
    return Array.from(select.selectedOptions).filter(function (option) {
      return option.value !== '';
    });
  }

  function mapUsageType(category) {
    switch ((category || '').toLowerCase()) {
      case 'antibody':
      case '抗体':
        return 'antibody';
      case 'primer':
      case '引物':
        return 'primer';
      case 'kit':
      case '试剂盒':
        return 'kit';
      case 'consumable':
      case '耗材':
        return 'consumable';
      case 'sample':
      case '样本':
        return 'sample';
      case 'instrument':
      case '仪器':
        return 'instrument';
      case 'other':
      case 'key_material':
      case '其他':
        return 'other';
      case 'reagent':
      case '试剂':
        return 'reagent';
      default:
        return '';
    }
  }

  function fillFromSelection(select) {
    const selected = selectedInventoryOptions(select);
    if (selected.length === 0) {
      const source = byId('smartlab-usage-source');
      if (source && source.value === 'inventory') {
        clearInventoryFields();
      }
      return;
    }

    const source = byId('smartlab-usage-source');
    if (source) {
      source.value = 'inventory';
    }

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

    const usageType = byId('smartlab-usage-type');
    const selectedTypes = Array.from(new Set(selected.map(function (option) {
      return mapUsageType(option.dataset.category || '');
    }).filter(Boolean)));
    if (usageType) {
      if (selectedTypes.length === 1) {
        usageType.value = selectedTypes[0];
      } else if (selectedTypes.length > 1) {
        usageType.value = 'other';
      }
    }
  }

  function matches(option, query) {
    if (!query) {
      return true;
    }
    const haystack = ((option.dataset.search || '') + ' ' + option.textContent).toLowerCase();
    return haystack.includes(query);
  }

  ready(function () {
    const select = byId('smartlab-usage-lot');
    const filter = byId('smartlab-usage-filter');
    const empty = byId('smartlab-usage-inventory-empty');

    if (!select) {
      return;
    }

    select.disabled = false;

    select.addEventListener('change', function () {
      fillFromSelection(select);
    });

    if (filter) {
      filter.addEventListener('input', function () {
        const query = filter.value.trim().toLowerCase();
        let visibleCount = 0;

        Array.from(select.options).forEach(function (option) {
          if (!option.value) {
            option.hidden = false;
            return;
          }
          const isMatch = matches(option, query);
          option.hidden = query !== '' && !isMatch && !option.selected;
          if (!option.hidden) {
            visibleCount += 1;
          }
        });

        if (empty) {
          empty.classList.toggle('d-none', visibleCount > 0 || query === '');
        }
      });
    }

    const source = byId('smartlab-usage-source');
    if (source) {
      source.addEventListener('change', function () {
        if (source.value !== 'manual') {
          return;
        }
        Array.from(select.options).forEach(function (option) {
          option.selected = false;
        });
        clearInventoryFields();
      });
    }
  });
}());
