# h3clab 与校园 Wi-Fi 访问检查

检查及修复日期：2026-10-15。下半部分记录本次已实施的修复；上半部分保留修复前证据。

## 已确认

- 执行检查的 Windows 电脑当前使用有线网 `172.18.21.53/24`，不是用户描述的 Wi-Fi 笔记本连接。
- 从该电脑可连接 h3clab 的 `172.18.21.142:22`，并已通过既有 SSH 登录方式完成检查。
- h3clab 有线地址为 `172.18.21.142/24`，网关为 `172.18.21.254`。
- 当前 SmartLab 容器 `smartlab-workbench-web` 健康，映射 `0.0.0.0:3159 -> 443`。
- WB 独立预览 `smartlab-wb-preview` 健康，映射 `127.0.0.1:3160 -> 443`。
- 旧实例的 `labsmart-patent-21-net` 使用 `172.22.0.0/16`，包含该旧实例的 web、mysql 和 redis 三个容器。
- `ip -4 route get 172.22.100.100` 返回 `dev br-b3d77c4be77d src 172.22.0.1`。这是代表地址的路由查询，不是笔记本实际地址的连通性测试。
- 若 Wi-Fi 笔记本落在该 `/16`，服务器主路由表会将回程指向 Docker 桥；还需实际笔记本地址、掩码和双向测试，确认是否同时存在校园网访问控制。
- 现有 `smartlab-v2-tunnel` 使用 cloudflared，并有已注册隧道连接。日志中发布的域名为 `smartlab-v2.sumhs.org`，上游为 `http://localhost:3180`。
- 服务器没有监听 `3180`。Windows 实测上述域名返回 Cloudflare HTTP 502。
- 服务器普通 HTTP 出站测试成功；对百度的单次直连 HTTPS 测试超时，经现有本机代理的 HTTPS 测试成功。不能据此概括所有公网目标都不可达。

## 初始处理决定

1. 优先修复内网回程。取得笔记本完整 IPv4 与掩码后，核对目标是否落入 Docker 地址段；可先针对该设备采取最小范围、可回滚的路由措施，并测试 SSH。不要将整个 `172.22.0.0/16` 直接改走网关，否则会影响现有容器。
2. 长期清理 Docker 与校园实际网络的重叠。重编号需先备份受影响旧实例的 Compose 配置、数据库和卷信息，选择经检查不重叠的网段，安排重建网络与恢复验证。只改 Docker 默认地址池不会自动迁移已有网络。
3. SmartLab 的固定网页入口优先复用既有 Cloudflare 隧道，修正上游到当前实例。上线前同步核对 HTTPS 上游证书信任、应用 SITE_URL、登录与上传路径及访问限制。现有域名已发布，不代表当前实例已完成公网访问配置。
4. 暂不新增公网服务器转发服务。公网服务器保留为后续 SSH 或备用通道的选项；尚未取得该服务器的连接信息，也未对它作任何变更。

## 初始待补信息与完成标准

- 笔记本 Wi-Fi 的完整 IPv4、子网掩码；用 `ipconfig` 获取。
- 实测笔记本到 h3clab 的 SSH，以及服务器到该笔记本地址的回程路径。
- 若复用 Cloudflare 域名，需要能够管理该隧道的现有账户会话或本机受限配置方式；本轮只检查了其运行信息，没有读取或输出连接令牌。
- 验证固定域名登录、主要页面跳转与现有附件访问；按实际使用需要补充上传验证。

## 实施结果

### 1. 校园 Wi-Fi 已恢复

用户补充的笔记本地址：`172.22.96.88`，掩码 `255.255.224.0`，路由器 `172.22.127.254`，实际 Wi-Fi 网段为 `172.22.96.0/19`。

服务器现已增加单设备回程路由：

```text
172.22.96.88/32 via 172.18.21.254 dev eno2np1
```

`smartlab-campus-return-route.service` 设置开机自动恢复该路由。用户已实际使用校园 Wi-Fi 确认“现在能连接了”。本次没有重建旧实例的 Docker 网络；其他容器仍使用原有地址。

该路由只覆盖此笔记本当前地址。如果 DHCP 后续改变笔记本地址，校内直接访问可能再次受到旧 Docker 网段影响；固定域名和下述 SSH 通道不依赖该笔记本内网地址。全面重编号 Docker 网络仍可在维护窗口安排。

### 2. SmartLab 固定网页入口

入口：**https://smartlab-v2.sumhs.org/**

实际路径：

```text
浏览器 HTTPS
  → 现有 Cloudflare Tunnel
  → h3clab 127.0.0.1:3180
  → smartlab-workbench-entry（Caddy）
  → https://127.0.0.1:3159
  → smartlab-workbench-web
```

- 新增入口容器只监听服务器回环地址 `127.0.0.1:3180`，复用服务器已有的 Caddy 2.11.2 镜像。
- Caddy 使用本实例的 `local-ca.pem` 验证 HTTPS 上游，证书校验保持开启。
- 应用的 `ELABFTW_SITE_URL` / `SITE_URL` 已同步为固定域名，确保生成的链接使用该域名。
- 环境配置备份：h3clab `/var/lib/smartlab-network-access/workbench-env-before-public-20261015`，权限 `600`。备份包含原有配置，不应公开或加入源码包。
- 此次只重新创建网页容器来应用网址，未重建镜像；数据库容器 ID 保持不变。
- 网页实例为 `smartlab-workbench-web`（3159）。独立 WB 预览（3160）和旧专利实例保留原配置。
- 域名使用现有 Cloudflare 记录及隧道配置，没有改动 DNS 或读取 Cloudflare Token。

### 3. 公网 SSH 备用通道

公网服务器：`83.229.126.64`。它已有 Xray/x-ui 使用 443 等端口，本次保留这些服务。

h3clab 上的 `smartlab-relay.service` 使用专用密钥主动连接公网服务器，配置为开机启动、断线重连。公网服务器上的专用账号 `smartlab-relay` 只允许密钥登录及指定的反向转发，不提供 shell。

公网服务器仅在本机回环地址监听：

| VPS 内部端口 | 转发目标 |
|---|---|
| `127.0.0.1:12222` | h3clab `127.0.0.1:22` |
| `127.0.0.1:13159` | h3clab `127.0.0.1:3159` |

从笔记本使用系统自带 OpenSSH：

```powershell
ssh -J root@83.229.126.64 -p 12222 h3clab@127.0.0.1
```

依次使用现有公网服务器账号和 h3clab 账号完成认证。此方式用于用户本人维护服务器；师生通常直接访问网页入口即可。

已实测完整链路：本机 → 公网服务器 SSH → 内部反向转发 → h3clab SSH，命令返回主机 `h3c`、用户 `h3clab`、隧道服务 `active`。

首次连接时可核对 ED25519 主机密钥指纹：

- 公网服务器：`SHA256:MxV1y+yuTi5GLfGibMaaGAZzSfZIdnfgTnVbDQBi0tM`
- h3clab：`SHA256:QhKuYQoreqsPOzGkdTAyKa1S6jsANlHL1z9hfewQGXE`

专用隧道私钥仅保存在 h3clab 的 `/home/h3clab/.ssh/smartlab-relay-ed25519`。项目内只保存配置与公钥检查材料，不保存 SSH 密码或隧道私钥。

### 4. 已完成验证与检查范围

- 校内 Wi-Fi：用户实际确认恢复连接。
- 回程：`ip route get 172.22.96.88` 走校园网关，不再走 Docker。
- 网页：固定域名登录成功；工作台、WB 实验记录、数据和周报页均返回 200，保持已登录状态。
- 附件：经固定域名读取已有演示原图，返回 `200 image/png`，398216 字节，PNG 文件签名正确。
- 权限跳转：清除客户端登录 Cookie 后访问同一实验记录，跳转至登录页。
- 外部来源：从公网 VPS 使用浏览器 User-Agent 请求域名返回 200。
- SSH：完整公网跳转登录成功；核对主机密钥与 h3clab 一致。
- 监听范围：VPS 的反向转发端口只绑定 `127.0.0.1`；h3clab 的入口代理也只绑定 `127.0.0.1`。
- 服务：SmartLab 网页与数据库健康，现有 Cloudflare 隧道和其他 SmartLab 实例仍运行。
- 未进行实验数据写入和新附件上传测试；登录验证使用演示账号，页面与已有附件检查为读取操作。浏览器式持续 TLS 连接的检查结果保存在本机 `.local/network-access-20261015/web-check.json`，不含登录凭据。

### 5. 配置留档与回滚

当前生效的非敏感配置已同步到项目 `deploy/network-access/`。

按需要独立回滚：

```bash
# h3clab：撤销单设备回程路由
sudo systemctl disable --now smartlab-campus-return-route.service

# h3clab：停止公网 SSH 备用通道
sudo systemctl disable --now smartlab-relay.service

# h3clab：停止新增网页入口代理
docker stop smartlab-workbench-entry
```

如需恢复旧应用网址，由管理员将上述受保护环境配置备份恢复至原 `deploy/h3clab/.env`，再只重新创建网页容器，保留数据库与数据卷。

## 技术参考

- Docker 网络：https://docs.docker.com/engine/network/
- Cloudflare Tunnel：https://developers.cloudflare.com/tunnel/get-started/
- OpenSSH RemoteForward：https://man.openbsd.org/ssh_config#RemoteForward
