(function(){
  'use strict';
  function disclosure(element,label,id){
    if(!element)return;
    var box=document.createElement('details');box.className='ls-disclosure';if(id)box.id=id;
    var summary=document.createElement('summary');summary.textContent=label;box.appendChild(summary);
    element.parentNode.insertBefore(box,element);box.appendChild(element);return box;
  }
  document.addEventListener('DOMContentLoaded',function(){
    disclosure(document.getElementById('topToolbar'),'更多记录操作');
    var parameters=disclosure(document.getElementById('experiment-assay-pack'),'完整参数表与既有结果','ls-record-parameters');
    disclosure(document.getElementById('smartlab-wb-workflow'),'辅助检查与证据详情（可选）','ls-record-checks');
    var body=document.getElementById('body_view'),workflow=document.getElementById('experiment-workflow');
    if(body&&workflow)disclosure(body.parentNode,'补充文字与既有正文');
    ['extraFieldsDiv','permissionsDiv','linksDiv','stepsDiv'].forEach(function(id){
      var panel=document.getElementById(id);if(!panel)return;
      var heading=panel.previousElementSibling;if(!heading||heading.tagName!=='H3')return;
      var box=disclosure(heading,heading.textContent.trim());
      heading.classList.add('ls-section-original');box.appendChild(panel);
    });
    if(parameters&&new URLSearchParams(location.search).has('smartlab_step'))parameters.open=true;
    document.querySelectorAll('[data-ls-open]').forEach(function(link){link.addEventListener('click',function(){var box=document.getElementById(link.dataset.lsOpen);if(box)box.open=true;});});
    document.querySelectorAll('a[href="#filesDiv"]').forEach(function(link){link.addEventListener('click',function(){['filesDiv','uploadsDiv'].forEach(function(id){var panel=document.getElementById(id);if(panel)panel.hidden=false;});});});
    document.querySelectorAll('.ls-review form').forEach(function(form){form.addEventListener('submit',function(event){var button=event.submitter;if(button&&button.value!=='approve'&&!form.elements.comment.value.trim()){event.preventDefault();form.elements.comment.setCustomValidity('请说明需要补充或修改的内容。');form.elements.comment.reportValidity();}});form.elements.comment.addEventListener('input',function(){this.setCustomValidity('');});});
  });
})();
