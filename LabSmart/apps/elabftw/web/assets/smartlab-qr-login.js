
window.onerror = function (m) { var s = document.getElementById('status'); if (s) { s.textContent = 'JS 错误：' + m; s.className = 'err'; } };
(function () {
  var qs = new URLSearchParams(location.search);
  var purpose = qs.get('purpose') || 'web';
  var label = qs.get('label') || '';
  var api = 'smartlab-qr-api.php';
  var challenge = null, timer = null;
  var $qr = document.getElementById('qr'), $s = document.getElementById('status'), $r = document.getElementById('refresh'), $done = document.getElementById('done');
  function status(text, cls) { $s.textContent = text; $s.className = cls || ''; }
  function draw(text) { var q = qrcode(0, 'M'); q.addData(text); q.make(); $qr.innerHTML = q.createImgTag(6, 8); }
  function start() {
    $done.hidden = true; $r.hidden = true; $qr.innerHTML = ''; status('正在生成二维码…');
    fetch(api + '?action=start', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'purpose=' + encodeURIComponent(purpose) + '&label=' + encodeURIComponent(label) })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) { status(d.error, 'err'); $r.hidden = false; return; }
        challenge = d.challenge; draw(d.scan_text); status('请用手机扫描二维码（2 分钟内有效）');
        if (timer) clearInterval(timer); timer = setInterval(poll, 2000);
      }).catch(function () { status('无法连接服务器。', 'err'); $r.hidden = false; });
  }
  function poll() {
    if (!challenge) return;
    fetch(api + '?action=poll&challenge=' + challenge).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { status(d.error, 'err'); return; }
      if (d.status === 'confirmed' && d.token) {
        clearInterval(timer); status('登录成功：' + ((d.user && d.user.name) || ''), 'ok');
        $qr.innerHTML = '<div style="font-size:44px;color:#1a7f4b">✓</div>';
        document.getElementById('token').textContent = d.token; $done.hidden = false;
      } else if (d.status === 'expired' || d.status === 'consumed' || d.status === 'cancelled') {
        clearInterval(timer); status('二维码已失效，请刷新。', 'err'); $r.hidden = false;
      }
    });
  }
  $r.addEventListener('click', start);
  start();
})();
