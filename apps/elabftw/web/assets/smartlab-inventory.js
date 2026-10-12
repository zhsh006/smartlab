(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('.smartlab-inventory-workbench');
    if (!root) {
      return;
    }

    var presets = {
      reagent: {
        category: 'reagent',
        code: 'MAT-RGT-',
        name: 'qPCR Mix / ECL 发光液',
        unit: 'mL',
        location: '试剂冰箱 A-2',
      },
      consumable: {
        category: 'consumable',
        code: 'MAT-CON-',
        name: '枪头 / 离心管 / PVDF 膜',
        unit: '盒',
        location: '耗材柜 B-1',
      },
      sample: {
        category: 'sample',
        code: 'SMP-',
        name: '血清样本 / 组织切片 / RNA 样本',
        unit: '管',
        location: '-80 冰箱 Box-',
      },
      antibody: {
        category: 'antibody',
        code: 'MAT-AB-',
        name: 'Anti-GAPDH / Anti-GFAP',
        unit: 'uL',
        location: '抗体盒 WB-1',
      },
    };

    root.querySelectorAll('[data-inventory-preset]').forEach(function (button) {
      button.addEventListener('click', function () {
        root.querySelectorAll('[data-inventory-preset]').forEach(function (item) {
          item.classList.remove('active');
        });
        button.classList.add('active');
        var preset = presets[button.getAttribute('data-inventory-preset')];
        if (!preset) {
          return;
        }
        var category = root.querySelector('#material-category');
        var code = root.querySelector('#material-code');
        var name = root.querySelector('#material-name');
        var unit = root.querySelector('#material-unit');
        var location = root.querySelector('#lot-location');
        if (category) {
          category.value = preset.category;
        }
        if (code && code.value === '') {
          code.value = preset.code;
        }
        if (name) {
          name.placeholder = preset.name;
        }
        if (unit && unit.value === '') {
          unit.value = preset.unit;
        }
        if (location && location.value === '') {
          location.value = preset.location;
        }
      });
    });

    var currentCategory = 'all';
    var currentRisk = 'all';
    var rows = Array.prototype.slice.call(root.querySelectorAll('#smartlabInventoryLotsBody .inventory-lot-row'));
    var noResult = root.querySelector('#smartlabInventoryNoFilterResult');

    function setActiveButton(groupSelector, activeButton) {
      root.querySelectorAll(groupSelector + ' .btn').forEach(function (button) {
        button.classList.remove('active');
      });
      activeButton.classList.add('active');
    }

    function applyFilters() {
      var visibleCount = 0;
      rows.forEach(function (row) {
        var categoryMatch = currentCategory === 'all' || row.getAttribute('data-category') === currentCategory;
        var riskMatch = currentRisk === 'all'
          || (currentRisk === 'low' && row.getAttribute('data-low') === '1')
          || (currentRisk === 'expiring' && row.getAttribute('data-expiring') === '1');
        var show = categoryMatch && riskMatch;
        row.classList.toggle('d-none', !show);
        if (show && row.tagName.toLowerCase() === 'tr' && !row.classList.contains('transaction-row')) {
          visibleCount += 1;
        }
      });
      if (noResult) {
        noResult.classList.toggle('d-none', visibleCount > 0);
      }
    }

    root.querySelectorAll('[data-filter-category]').forEach(function (button) {
      button.addEventListener('click', function () {
        currentCategory = button.getAttribute('data-filter-category');
        setActiveButton('[data-filter-group="category"]', button);
        applyFilters();
      });
    });

    root.querySelectorAll('[data-filter-risk]').forEach(function (button) {
      button.addEventListener('click', function () {
        currentRisk = button.getAttribute('data-filter-risk');
        setActiveButton('[data-filter-group="risk"]', button);
        applyFilters();
      });
    });
  });
}());
