(function () {
  'use strict';
  document.querySelectorAll('[data-wb-manual]').forEach(function (section) {
    var form = section.closest('form'), button = section.querySelector('[data-wb-extract]'), status = section.querySelector('[data-wb-extract-status]');
    function touched() { section.querySelector('[name="wb_profile_present"]').value = '1'; }
    section.addEventListener('input', touched); section.addEventListener('change', touched);
    button.addEventListener('click', async function () {
      button.disabled = true; status.textContent = '正在读取说明书…';
      try {
        var data = new FormData(form); data.set('smartlab_action', 'extract_wb_sheet');
        var response = await fetch('/wb-conditions.php?smartlab_view=assistant', {method:'POST', body:data, credentials:'same-origin'});
        var result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || '未能提取，请检查说明书。');
        Object.keys(result.fields).forEach(function (key) {
          var input = form.elements['wb_profile[' + key + ']'];
          if (input) input.value = result.fields[key];
          if (key === 'vendor' && form.elements.material_brand && !form.elements.material_brand.value) form.elements.material_brand.value = result.fields[key];
          if (key === 'catalog_no' && form.elements.material_catalog_no && !form.elements.material_catalog_no.value) form.elements.material_catalog_no.value = result.fields[key];
        });
        // Replacing a source cannot silently keep absent recommendations from its predecessor.
        ['host_species','reactivity','target','molecular_weight','clone','storage','wb_dilution','wb_diluent','wb_incubation'].forEach(function (key) { if (!Object.prototype.hasOwnProperty.call(result.fields,key)) form.elements['wb_profile[' + key + ']'].value = ''; });
        var reviewed = form.elements['wb_profile[reviewed]']; if (reviewed) reviewed.checked = false;
        touched(); status.textContent = result.message;
      } catch (error) { status.textContent = error.message; }
      finally { button.disabled = false; }
    });
  });
  var category = document.querySelector('[name="material_category"]'), panel = document.getElementById('inbound-wb-manual');
  if (category && panel) {
    function toggle() { panel.hidden = category.value !== 'antibody'; panel.querySelectorAll('input,select,textarea,button').forEach(function (input) { input.disabled = panel.hidden; }); }
    category.addEventListener('change', toggle); toggle();
    document.querySelectorAll('[data-inventory-preset]').forEach(function (button) { button.addEventListener('click', function () { setTimeout(toggle,0); }); });
  }
})();
