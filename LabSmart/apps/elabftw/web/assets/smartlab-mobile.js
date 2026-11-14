
var API = { drive: 'smartlab-drive-api.php', qr: 'smartlab-qr-api.php', mobile: 'smartlab-mobile-api.php' };
var token = localStorage.getItem('sl_token') || '';
var name = localStorage.getItem('sl_name') || '';
var challenge = new URLSearchParams(location.search).get('c') || '';
var app = document.getElementById('app');
function h(html) { app.innerHTML = html; }
function setWho() { document.getElementById('who').textContent = name || ''; if (!name) { } }
function req(url, opts) { opts = opts || {}; opts.headers = opts.headers || {}; if (token) opts.headers['X-SmartLab-Token'] = token; return fetch(url, opts).then(function (r) { return r.json(); }); }
function form(action, fields) { var b = new URLSearchParams(); b.set('action', action); for (var k in fields) b.set(k, fields[k]); return { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: b.toString() }; }

function viewLogin(afterConfirm) {
  h('<div class=card><h2>登录 LabSmart</h2><p class=muted>首次使用请用你的账号登录，之后可长期保持。</p>'
    + '<label>邮箱</label><input id=email type=email placeholder=you@lab.com>'
    + '<label>密码</label><input id=pw type=password placeholder=密码>'
    + '<button id=do>登录</button><div class=err id=e></div></div>');
  document.getElementById('do').onclick = function () {
    var email = document.getElementById('email').value, pw = document.getElementById('pw').value;
    fetch(API.drive, form('login', { email: email, password: pw, device: '手机端' })).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { document.getElementById('e').textContent = d.error; return; }
      token = d.token; name = (d.user && d.user.name) || d.user.email; localStorage.setItem('sl_token', token); localStorage.setItem('sl_name', name); setWho();
      if (afterConfirm) viewConfirm(); else viewList();
    });
  };
}
function viewConfirm() {
  if (!token) { viewLogin(true); return; }
  h('<div class=card><h2>确认登录</h2><p class=muted>你正在确认让电脑端用你的账号登录。</p>'
    + '<button id=ok>确认登录</button><button class=ghost id=no>取消</button><div class=err id=e></div></div>');
  document.getElementById('ok').onclick = function () {
    fetch(API.qr, Object.assign(form('confirm', { challenge: challenge, label: '扫码登录' }), { headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-SmartLab-Token': token } }))
      .then(function (r) { return r.json(); }).then(function (d) { if (d.ok) { h('<div class=card><h2>✓ 已确认</h2><p class=muted>请回到电脑端查看。</p></div>'); } else { document.getElementById('e').textContent = d.error; } });
  };
  document.getElementById('no').onclick = function () { fetch(API.qr, form('cancel', { challenge: challenge })); h('<div class=card><p class=muted>已取消。</p></div>'); };
}
function viewList() {
  if (!token) { viewLogin(false); return; }
  req(API.mobile + '?action=experiments').then(function (d) {
    if (!d.ok) { document.getElementById('who').textContent = d.error; return; }
    var rows = d.experiments.map(function (e) {
      return '<div class=row onclick="openExp(' + e.id + ')"><div class=t><b>' + esc(e.title) + '</b><span>' + esc(e.date) + (e.template ? ' · ' + esc(e.template) : '') + (e.mine ? ' · 我的' : '') + '</span></div><span class=chip>›</span></div>';
    }).join('');
    h('<div class=card><h2>我的实验</h2><p class=muted>共 ' + d.experiments.length + ' 条</p></div><div class=card>' + (rows || '<p class=muted>还没有实验。</p>') + '</div>');
  });
}
function openExp(id) {
  req(API.mobile + '?action=experiment&id=' + id).then(function (d) {
    if (!d.ok) { h('<div class=card><p class=err>' + esc(d.error) + '</p></div>'); return; }
    var e = d.experiment;
    var steps = e.steps.map(function (s) { return '<div class=step><span class="dot ' + (s.state === 'confirmed' ? 'done' : '') + '">' + (s.state === 'confirmed' ? '✓' : '') + '</span><div class=t><b>' + esc(s.label) + '</b></div></div>'; }).join('');
    h('<div class=wrap></div>'
      + '<div class=card><a class=back href=javascript:void(0) onclick=viewList()>‹ 返回</a><h2>' + esc(e.title) + '</h2><p class=muted>' + esc(e.template) + ' · ' + esc(e.date) + ' · 附件 ' + e.uploads + '</p></div>'
      + '<div class=card><b>实验目的</b><p>' + esc(e.objective || '未记录') + '</p></div>'
      + '<div class=card><b>阶段</b>' + (steps || '<p class=muted>尚未记录</p>') + '</div>'
      + '<div class=card><b>结果与结论</b><p>' + esc(e.summary || '未记录') + '</p><p class=muted>' + esc(e.conclusion || '') + '</p></div>');
  });
}
function viewMe() {
  req(API.mobile + '?action=me').then(function (d) {
    h('<div class=card><h2>我的</h2><p class=muted>' + esc((d.user && d.user.name) || '') + '<br>' + esc((d.user && d.user.email) || '') + '</p>'
      + '<button class=ghost id=out>退出登录</button></div>');
    document.getElementById('out').onclick = function () { localStorage.removeItem('sl_token'); localStorage.removeItem('sl_name'); token = ''; name = ''; setWho(); viewList(); };
  });
}
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
document.getElementById('nav-home').onclick = viewList;
document.getElementById('nav-me').onclick = viewMe;
document.getElementById('nav-scan').onclick = function () { if (challenge) viewConfirm(); else h('<div class=card><h2>扫一扫登录</h2><p class=muted>请先用手机扫描电脑端显示的二维码。</p></div>'); };
window.openExp = openExp; window.viewList = viewList;
setWho();
if (challenge) viewConfirm(); else viewList();
