(function () {
  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function uploadFiles(url, files) {
    var csrf = getCsrfToken();
    return Promise.all(files.map(function (file) {
      var formData = new FormData();
      formData.append('file', file, file.name);
      return fetch(url, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
          'X-CSRF-Token': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
      }).then(function (response) {
        if (!response.ok) {
          return response.json().catch(function () {
            return {};
          }).then(function (json) {
            throw new Error(json.message || json.description || '上传失败');
          });
        }
      });
    }));
  }

  function openFallbackInput(dropzone) {
    var fallbackInput = document.getElementById('smartlab-dropzone-fallback-input');
    if (!fallbackInput) {
      fallbackInput = document.createElement('input');
      fallbackInput.type = 'file';
      fallbackInput.multiple = true;
      fallbackInput.id = 'smartlab-dropzone-fallback-input';
      fallbackInput.style.display = 'none';
      document.body.appendChild(fallbackInput);
    }

    fallbackInput.onchange = function () {
      var files = Array.prototype.slice.call(fallbackInput.files || []);
      if (!files.length) {
        return;
      }
      uploadFiles(dropzone.getAttribute('action'), files).then(function () {
        window.location.reload();
      }).catch(function (error) {
        window.alert(error.message || '上传失败');
      }).finally(function () {
        fallbackInput.value = '';
      });
    };
    fallbackInput.click();
  }

  function openUploadDialog(dropzone) {
    var input = document.querySelector('input.dz-hidden-input[type="file"]');
    if (input) {
      input.click();
      return;
    }

    var targetDropzone = dropzone || document.getElementById('elabftw-dropzone');
    if (targetDropzone) {
      openFallbackInput(targetDropzone);
    }
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    var dropzone = target.closest ? target.closest('#elabftw-dropzone') : null;
    if (!dropzone) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    openUploadDialog(dropzone);
  }, true);

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-smartlab-open-upload-dialog]');
    if (!button) {
      return;
    }
    event.preventDefault();
    openUploadDialog();
  });

  document.addEventListener('change', function (event) {
    var input = event.target.closest('[data-smartlab-direct-upload]');
    if (!input || !input.files || input.files.length === 0) {
      return;
    }

    var experimentId = input.dataset.experimentId;
    var label = input.closest('label');
    var labelText = label ? label.querySelector('[data-smartlab-direct-upload-label]') : null;
    var files = Array.prototype.slice.call(input.files);

    input.disabled = true;
    if (label) {
      label.classList.add('disabled');
    }
    if (labelText) {
      labelText.textContent = '上传中...';
    }

    uploadFiles('api/v2/experiments/' + encodeURIComponent(experimentId) + '/uploads', files).then(function () {
      window.location.href = 'experiments.php?mode=edit&id=' + encodeURIComponent(experimentId) + '&smartlab_step=upload#filesDiv';
    }).catch(function (error) {
      window.alert(error.message || '上传失败');
      input.disabled = false;
      input.value = '';
      if (label) {
        label.classList.remove('disabled');
      }
      if (labelText) {
        labelText.textContent = '上传';
      }
    });
  });
})();
