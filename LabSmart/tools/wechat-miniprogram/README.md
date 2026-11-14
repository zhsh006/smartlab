# LabSmart 微信小程序（手机端）

三端之一：**手机端**。用本账号扫码登录 **服务端（Web）** 和 **Agent 端（仪器电脑）**，并可查看实验。

## 目录
- `app.json/app.js/app.wxss`：小程序框架
- `utils/api.js`：接口封装（登录、扫码确认、实验列表/详情）
- `pages/index` 扫一扫（登录 + wx.scanCode 确认）
- `pages/experiments` 我的实验
- `pages/experiment` 实验详情
- `pages/me` 我的

## 重要：微信环境限制（必读）
1. **必须在 `utils/api.js` 把 `baseUrl` 换成你自己的域名**，且该域名要满足：
   - **HTTPS**（有效证书，不能自签）；
   - 已在 **`mp.weixin.qq.com → 开发管理 → 服务器域名 → request 合法域名`** 里登记；
   - 微信**不接受 IP 地址**，因此当前内网地址 `172.18.21.142` 在微信里**无法调用**。
2. 需要在微信公众平台注册小程序拿到 **AppID**，填到 `project.config.json` 的 `appid`，用**微信开发者工具**导入本目录。
3. 后端新增了 3 个接口：`smartlab-qr-api.php`、`smartlab-mobile-api.php`、`smartlab-mobile.php`（及页面 `smartlab-qr-login.php`）。登录复用 `smartlab-drive-api.php?action=login`。

## 扫码登录流程
1. 电脑端（Web/Agent）打开 **`/smartlab-qr-login.php`**（或 Agent 内置），调用 `start` 得到二维码；
2. 手机小程序登录后，点「扫一扫」→ `wx.scanCode` 读到二维码里的 challenge；
3. 小程序调 `smartlab-qr-api.php?action=confirm`（带自己的令牌）确认；
4. 电脑端 `poll` 到 `confirmed`，拿到 app 令牌即完成登录。

## 今天就能演示的替代方案
微信未就绪前，用手机浏览器打开 **`/smartlab-mobile.php?c=<challenge>`**（二维码里就是这个地址）即可完成同样的登录确认；不扫码时它就是手机版「我的实验」。
