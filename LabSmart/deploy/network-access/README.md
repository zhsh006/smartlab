# SmartLab 网络访问配置

此目录保存 2026-10-15 实际生效的非敏感配置快照。

- `smartlab-campus-return-route.service`：h3clab 到用户 Wi-Fi 笔记本的单设备回程路由。
- `smartlab-relay.service`：h3clab 主动连接 VPS 的 SSH 转发服务。
- `60-smartlab-relay.conf`：VPS 专用隧道账号的 SSH 限制。
- `Caddyfile`：h3clab 回环地址 3180 上的 Cloudflare 入口代理。

部署结果、访问命令、指纹、受保护配置备份位置与回滚说明：见 `../../docs/network-access-review-20261015.md`。

这是两台服务器的配置快照，不是一个可以在任意机器上直接全部安装的脚本。SSH 密码、隧道私钥、Cloudflare Token 和数据库环境配置不属于本目录。
