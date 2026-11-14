<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang='zh-CN'>
<head>
<meta charset='utf-8'>
<meta name='viewport' content='width=device-width, initial-scale=1'>
<title>扫码登录 · LabSmart</title>
<style>
  :root { --blue:#0a66c2; --ink:#1e2733; --muted:#66727f; --line:#e2e7ee; --bg:#f3f5f8; }
  * { box-sizing: border-box; }
  body { align-items: center; background: var(--bg); color: var(--ink); display: flex; font-family: "Microsoft YaHei","PingFang SC",-apple-system,"Segoe UI",sans-serif; justify-content: center; margin: 0; min-height: 100vh; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 12px 40px rgba(16,24,40,.08); max-width: 380px; padding: 28px 30px 24px; text-align: center; width: 100%; }
  .brand { align-items: center; display: flex; gap: 10px; justify-content: center; margin-bottom: 18px; }
  .brand .mark { background: var(--blue); border-radius: 9px; color: #fff; display: grid; font-size: 16px; font-weight: 700; height: 34px; place-items: center; width: 34px; }
  .brand b { font-size: 18px; }
  h1 { font-size: 17px; margin: 4px 0 2px; }
  p.hint { color: var(--muted); font-size: 13px; margin: 0 0 18px; }
  #qr { align-items: center; display: flex; justify-content: center; min-height: 220px; }
  #qr img { border: 1px solid var(--line); border-radius: 8px; padding: 10px; }
  #status { color: var(--muted); font-size: 13px; margin-top: 14px; min-height: 20px; }
  #status.ok { color: #1a7f4b; font-weight: 600; }
  #status.err { color: #c0392b; }
  button { background: var(--blue); border: 0; border-radius: 6px; color: #fff; cursor: pointer; font-size: 14px; margin-top: 14px; padding: 9px 18px; }
  .tok { background: var(--bg); border: 1px dashed var(--line); border-radius: 6px; font-family: Consolas, monospace; font-size: 12px; margin-top: 12px; overflow-wrap: anywhere; padding: 10px; text-align: left; }
</style>
</head>
<body>
<div class='card'>
  <div class='brand'><span class='mark'>L</span><b>LabSmart</b></div>
  <h1 id='title'>扫码登录</h1>
  <p class='hint'>用微信小程序或手机浏览器扫描下方二维码，确认后用本账号登录本机。</p>
  <div id='qr'></div>
  <div id='status'>正在生成二维码…</div>
  <button id='refresh' type='button' hidden>刷新二维码</button>
  <div id='done' hidden><div class='tok' id='token'></div><p class='hint' style='margin-top:8px'>将该令牌填入 Agent（或已自动登录）。</p></div>
</div>
<script src='/assets/qrcode.js'></script>
<script src='/assets/smartlab-qr-login.js'></script>
</body>
</html>
