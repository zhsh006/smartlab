<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang='zh-CN'>
<head>
<meta charset='utf-8'>
<meta name='viewport' content='width=device-width, initial-scale=1, maximum-scale=1'>
<title>LabSmart 手机端</title>
<style>
  :root { --blue:#0a66c2; --ink:#1e2733; --muted:#66727f; --line:#e2e7ee; --bg:#f3f5f8; }
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  body { background: var(--bg); color: var(--ink); font-family: "PingFang SC","Microsoft YaHei",-apple-system,sans-serif; margin: 0; padding: 0 0 72px; }
  header { align-items: center; background: #fff; border-bottom: 1px solid var(--line); display: flex; gap: 10px; padding: 14px 16px; position: sticky; top: 0; }
  header .mark { background: var(--blue); border-radius: 8px; color: #fff; display: grid; font-size: 14px; font-weight: 700; height: 28px; place-items: center; width: 28px; }
  header b { font-size: 16px; }
  header .who { color: var(--muted); font-size: 13px; margin-left: auto; }
  .wrap { padding: 16px; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; margin-bottom: 12px; padding: 16px; }
  h2 { font-size: 18px; margin: 4px 0 6px; }
  .muted { color: var(--muted); font-size: 13px; }
  label { display: block; font-size: 12px; font-weight: 600; margin: 10px 0 4px; }
  input { border: 1px solid var(--line); border-radius: 8px; font-size: 16px; padding: 11px 12px; width: 100%; }
  button { background: var(--blue); border: 0; border-radius: 8px; color: #fff; font-size: 15px; padding: 12px 16px; width: 100%; margin-top: 14px; }
  button.ghost { background: #fff; border: 1px solid var(--line); color: var(--ink); }
  .row { align-items: center; border-bottom: 1px solid var(--line); display: flex; gap: 10px; padding: 13px 0; }
  .row:last-child { border-bottom: 0; }
  .row .t { flex: 1; min-width: 0; }
  .row .t b { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .row .t span { color: var(--muted); font-size: 12px; }
  .chip { background: #eef1f5; border-radius: 999px; color: var(--muted); font-size: 12px; padding: 3px 10px; }
  .chip.ok { background: #e6f4ec; color: #1a7f4b; }
  .step { display: flex; gap: 10px; padding: 9px 0; border-bottom: 1px solid #eef1f6; }
  .step:last-child { border-bottom: 0; }
  .dot { align-items: center; background: #eef1f5; border-radius: 50%; color: #5b6675; display: inline-flex; flex: 0 0 auto; font-size: 12px; height: 22px; justify-content: center; width: 22px; }
  .dot.done { background: #e6f4ec; color: #1a7f4b; }
  .back { color: var(--blue); font-size: 14px; }
  .nav { background: #fff; border-top: 1px solid var(--line); bottom: 0; display: flex; left: 0; position: fixed; right: 0; }
  .nav a { color: var(--muted); flex: 1; font-size: 12px; padding: 10px 0; text-align: center; text-decoration: none; }
  .nav a.active { color: var(--blue); font-weight: 600; }
  .err { color: #c0392b; font-size: 13px; margin-top: 8px; }
</style>
</head>
<body>
<header><span class='mark'>L</span><b>LabSmart</b><span class='who' id='who'></span></header>
<div class='wrap' id='app'></div>
<nav class='nav'>
  <a href='javascript:void(0)' id='nav-home' class='active'>我的实验</a>
  <a href='javascript:void(0)' id='nav-scan'>扫一扫登录</a>
  <a href='javascript:void(0)' id='nav-me'>我的</a>
</nav>
<script src='/assets/smartlab-mobile.js'></script>
</body>
</html>
