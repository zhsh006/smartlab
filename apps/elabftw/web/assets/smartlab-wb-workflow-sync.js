(function () {
  'use strict';

  var textOf = function (selector) {
    var input = document.querySelector(selector);
    return input && 'value' in input ? String(input.value || '').trim() : '';
  };

  var inputs = function (selector) {
    return Array.prototype.slice.call(document.querySelectorAll(selector));
  };

  var hasAnyValue = function (selector) {
    return inputs(selector).some(function (input) {
      return String(input.value || '').trim() !== '';
    });
  };

  var filledResultCount = function () {
    var labels = inputs('select[name="wb_result_sample_label[]"], input[name="wb_result_sample_label[]"]');
    var targets = inputs('input[name="wb_result_target_signal[]"]');
    var references = inputs('input[name="wb_result_reference_signal[]"]');
    var normalized = inputs('input[name="wb_result_normalized_signal[]"]');
    var count = Math.max(labels.length, targets.length, references.length, normalized.length);
    var filled = 0;
    for (var i = 0; i < count; i += 1) {
      var label = labels[i] ? String(labels[i].value || '').trim() : '';
      var target = targets[i] ? String(targets[i].value || '').trim() : '';
      var reference = references[i] ? String(references[i].value || '').trim() : '';
      var ratio = normalized[i] ? String(normalized[i].value || '').trim() : '';
      if (label !== '' || target !== '' || reference !== '' || ratio !== '') {
        filled += 1;
      }
    }
    return filled;
  };

  var setBoxTone = function (box, state) {
    if (!box) {
      return;
    }
    box.classList.remove('border-success', 'border-warning');
    if (state === 'success') {
      box.classList.add('border-success');
    } else if (state === 'warning') {
      box.classList.add('border-warning');
    }
    var value = box.querySelector('.font-weight-bold');
    if (value) {
      value.classList.remove('text-success', 'text-warning');
      if (state === 'success') {
        value.classList.add('text-success');
      } else if (state === 'warning') {
        value.classList.add('text-warning');
      }
    }
  };

  var setSummary = function (key, value, state) {
    var box = document.querySelector('[data-smartlab-wb-summary="' + key + '"]');
    if (!box) {
      return;
    }
    var valueNode = box.querySelector('.font-weight-bold');
    if (valueNode) {
      valueNode.textContent = value;
    }
    setBoxTone(box, state);
  };

  var setModule = function (key, detail, state) {
    var row = document.querySelector('[data-smartlab-wb-module="' + key + '"]');
    if (!row) {
      return;
    }
    var label = row.querySelector('.font-weight-bold');
    var detailNode = row.querySelector('.smallgray.flex-fill');
    if (detailNode) {
      detailNode.textContent = detail;
    }
    if (label) {
      label.classList.remove('text-success', 'text-warning');
      if (state === 'success') {
        label.classList.add('text-success');
      } else if (state === 'warning') {
        label.classList.add('text-warning');
      }
    }
  };

  var setStep = function (num, detail, status, badgeText, state) {
    var row = document.querySelector('[data-smartlab-wb-step="' + num + '"]');
    if (!row) {
      return;
    }
    var detailNode = row.querySelector('td:first-child .smallgray');
    var statusNode = row.querySelector('td:nth-child(2)');
    var badge = row.querySelector('td:nth-child(3) .badge');
    if (detailNode) {
      detailNode.textContent = detail;
    }
    if (statusNode) {
      statusNode.textContent = status;
    }
    if (badge) {
      badge.textContent = badgeText;
      badge.classList.remove('badge-success', 'badge-warning', 'badge-light');
      badge.classList.add(state === 'success' ? 'badge-success' : (state === 'warning' ? 'badge-warning' : 'badge-light'));
    }
  };

  var setStudentWorkflowStep = function (key, label, state) {
    var link = document.querySelector('button[data-smartlab-step-link="' + key + '"]');
    if (!link) {
      return;
    }
    link.textContent = label;
    link.classList.remove('border-primary', 'text-primary', 'text-muted', 'border-success', 'text-success');
    if (state === 'success') {
      link.classList.add('border-success', 'text-success');
    } else if (state === 'active') {
      link.classList.add('border-primary', 'text-primary');
      link.setAttribute('aria-current', 'step');
    } else {
      link.classList.add('text-muted');
    }
  };

  var setWorkflowAlert = function (recognized, hasResults) {
    var alert = document.querySelector('[data-smartlab-workflow-alert]');
    if (!alert) {
      return;
    }
    alert.classList.remove('alert-success', 'alert-warning', 'alert-info');
    if (hasResults) {
      alert.classList.add('alert-success');
      alert.textContent = '结果已回填。点击“进行智能检测”，按“原始记录上传 → 智能识别 → 断点识别 → 跳步识别 → 模板补全/草稿”完成检查后，在模板补全/草稿页提交导师审核。';
    } else if (recognized) {
      alert.classList.add('alert-warning');
      alert.textContent = '智能识别与断点检查已完成。下一步请回填 WB 结构化结果并保存记录。';
    } else {
      alert.classList.add('alert-warning');
      alert.textContent = '已上传原始数据。下一步请打开 WB 智能识别，完成 lane/ROI 识别、原图关联和断点检查。';
    }
  };

  var sync = function () {
    if (!document.getElementById('smartlab-wb-workflow')) {
      return;
    }
    var recognized = textOf('#wb-raw-image-ref') !== ''
      || textOf('#wb-cropped-image-ref') !== ''
      || textOf('#wb-annotation-note') !== ''
      || hasAnyValue('input[name="wb_result_target_signal[]"]')
      || hasAnyValue('input[name="wb_result_reference_signal[]"]')
      || hasAnyValue('input[name="wb_result_normalized_signal[]"]');
    var resultCount = filledResultCount();
    var hasResults = resultCount > 0;

    setSummary('recognition', recognized ? '已关联' : '待关联', recognized ? 'success' : 'warning');
    setSummary('results', resultCount + ' 行', hasResults ? 'success' : (recognized ? 'warning' : ''));
    setStudentWorkflowStep(
      'recognize',
      '3. 智能识别与断点检查 · ' + (recognized ? '已完成' : '当前步骤'),
      recognized ? 'success' : 'active'
    );
    setStudentWorkflowStep(
      'results',
      '4. 结果回填确认 · ' + (hasResults ? '已完成' : '待完成'),
      hasResults ? 'success' : ''
    );
    if (hasResults) {
      setStudentWorkflowStep('submit', '5. 进行智能检测 · 当前步骤', 'active');
    }
    setWorkflowAlert(recognized, hasResults);

    if (recognized) {
      setModule('record', '已形成原图、lane/ROI 标注和结构化字段索引。', 'success');
      setStep('04', '已完成 lane/ROI 识别并关联 WB 原图证据', '已完成', '已识别', 'success');
    } else {
      setModule('record', '上传后会建立图像、附件名和结构化字段索引。', 'warning');
      setStep('04', '已上传附件，等待 lane/ROI 识别', '当前处理', '推理补全', 'warning');
    }

    if (hasResults) {
      setModule('templates', '可将 ' + resultCount + ' 行灰度结果写入 WB 过程草稿并送审。', 'success');
      setStep('05', resultCount + ' 行结构化结果已回填', '已完成', '已识别', 'success');
    } else {
      setModule('templates', '等待结果回填后生成完整 WB 过程草稿。', '');
      setStep('05', '等待目标/内参灰度与归一化结果', recognized ? '当前处理' : '待完成', recognized ? '推理补全' : '待补全', recognized ? 'warning' : '');
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    var selectors = [
      '#wb-raw-image-ref',
      '#wb-cropped-image-ref',
      '#wb-annotation-note',
      'select[name="wb_result_sample_label[]"]',
      'input[name="wb_result_sample_label[]"]',
      'input[name="wb_result_target_signal[]"]',
      'input[name="wb_result_reference_signal[]"]',
      'input[name="wb_result_normalized_signal[]"]',
      'select[name="wb_result_trend[]"]',
      'input[name="wb_result_trend[]"]'
    ];
    selectors.forEach(function (selector) {
      document.querySelectorAll(selector).forEach(function (input) {
        input.addEventListener('input', sync);
        input.addEventListener('change', sync);
      });
    });
    document.addEventListener('input', function (event) {
      if (event.target && event.target.closest && event.target.closest('#smartlab-wb-result-table')) {
        sync();
      }
    });
    document.addEventListener('change', function (event) {
      if (event.target && event.target.closest && event.target.closest('#smartlab-wb-result-table')) {
        sync();
      }
    });
    window.setTimeout(sync, 0);
    window.addEventListener('popstate', function () {
      window.setTimeout(sync, 0);
    });
    sync();
  });
}());
