(function () {
  function initSmartlabStepRouter() {
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-smartlab-step-panel]')).filter(function (panel) {
      return panel.id !== 'filesDiv';
    });
    var links = Array.prototype.slice.call(document.querySelectorAll('[data-smartlab-step-link]'));
    if (!panels.length || !links.length) {
      return;
    }

    var validSteps = new Set(['prepare', 'upload', 'recognize', 'results', 'submit']);
    var params = new URLSearchParams(window.location.search);
    var activeBadge = links.find(function (link) {
      return link.classList.contains('border-primary');
    });
    var initial = validSteps.has(params.get('smartlab_step'))
      ? params.get('smartlab_step')
      : ((activeBadge && activeBadge.dataset.smartlabStepLink) || 'prepare');
    var nextStep = { prepare: 'upload', upload: 'recognize', recognize: 'results', results: 'submit' };

    function setStep(step, updateUrl, scroll) {
      if (!validSteps.has(step)) {
        step = 'prepare';
      }
      panels.forEach(function (panel) {
        panel.hidden = panel.dataset.smartlabStepPanel !== step;
      });
      links.forEach(function (link) {
        var isActive = link.dataset.smartlabStepLink === step;
        link.classList.toggle('border-primary', isActive);
        link.classList.toggle('text-primary', isActive);
        link.classList.toggle('text-muted', !isActive && !link.classList.contains('text-success'));
        if (isActive) {
          link.setAttribute('aria-current', 'step');
        } else {
          link.removeAttribute('aria-current');
        }
      });
      if (updateUrl) {
        var nextParams = new URLSearchParams(window.location.search);
        nextParams.set('smartlab_step', step);
        window.history.pushState({ smartlabStep: step }, '', window.location.pathname + '?' + nextParams.toString());
      }
      if (scroll) {
        var target = panels.find(function (panel) {
          return panel.dataset.smartlabStepPanel === step;
        });
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    }

    var assayAction = document.querySelector('#experiment-assay-pack input[name="smartlab_action"][value="save_assay_pack"]');
    var assayForm = assayAction ? assayAction.closest('form') : null;
    if (assayForm) {
      assayForm.addEventListener('submit', async function (event) {
        var submitter = event.submitter;
        if (!submitter || !submitter.matches('[data-smartlab-save-next]')) {
          return;
        }
        event.preventDefault();
        submitter.disabled = true;
        var originalText = submitter.textContent;
        submitter.textContent = '正在保存...';
        try {
          var current = new URLSearchParams(window.location.search).get('smartlab_step') || initial;
          var beforeSave = new CustomEvent('smartlab:before-save-next', {
            bubbles: true,
            cancelable: true,
            detail: {
              step: current,
              form: assayForm,
              submitter: submitter,
            },
          });
          if (!assayForm.dispatchEvent(beforeSave)) {
            throw new Error('保存前检查未通过');
          }
          var response = await fetch(assayForm.action, {
            method: 'POST',
            body: new FormData(assayForm),
            credentials: 'same-origin',
          });
          if (!response.ok) {
            throw new Error('保存失败');
          }
          setStep(nextStep[current] || 'upload', true, true);
        } catch (error) {
          window.alert(error.message || '保存失败，请重试。');
        } finally {
          submitter.disabled = false;
          submitter.textContent = originalText;
        }
      });
    }

    links.forEach(function (link) {
      link.addEventListener('click', function (event) {
        var step = link.dataset.smartlabStepLink;
        if (!validSteps.has(step)) {
          return;
        }
        event.preventDefault();
        setStep(step, true, true);
      });
    });
    window.addEventListener('popstate', function () {
      var next = new URLSearchParams(window.location.search).get('smartlab_step') || initial;
      setStep(next, false, true);
    });
    setStep(initial, false, false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSmartlabStepRouter);
  } else {
    initSmartlabStepRouter();
  }
})();
