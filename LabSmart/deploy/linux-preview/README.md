# h3clab 独立预览

部署日期：2026-10-14。收尾验证：2026-10-15。

- 服务器目录：`/home/h3clab/smartlab-previews/wb-20261014`
- Compose 项目：`smartlab-wb-preview-20261014`
- 容器：`smartlab-wb-preview`、`smartlab-wb-preview-mysql`
- 浏览器：[https://localhost:3160/](https://localhost:3160/)
- 学生演示：[WB 使用流程演示（合成数据）](https://localhost:3160/smartlab-record.php?id=15)

应用实际运行在 Linux，浏览器通过本机 SSH 转发访问服务器的 `127.0.0.1:3160`。此地址依赖本机转发进程，不是服务器公开网址；当前其他电脑不能直接用该地址访问。沿用已经信任的 localhost 开发证书，数据库端口不对外开放。

本机重启或转发断开后，在项目目录运行：

```powershell
./tools/open-linux-preview.ps1
```

专用转发密钥保存在 `.local/remote-preview`，仅允许转发至该预览端口，不能执行服务器命令。`.local` 和服务器 `.env` 包含私密配置，不应放入公开源码包。

预览数据库恢复自 2026-10-14 06:12 的本地逻辑备份，之后在本机新增的记录未同步。C 盘完整源码及本机数据库仍保留。本次没有替换服务器原有的 SmartLab 实例；它们与预览使用不同目录、容器、数据库和端口。

服务器内执行更新（源码须先同步到本目录）：

```bash
cd /home/h3clab/smartlab-previews/wb-20261014
python3 tools/deploy-linux-preview.py
```

脚本只接受此目录，首次恢复要求空数据库；更新前保留逻辑备份，显式应用 002、003 迁移，构建后检查启动、记录保存、版本冻结和原始附件哈希。测试数据事务回滚；独立的可视化演示记录保留，均标为合成数据。MySQL 与会话使用该 Compose 项目专用卷，源码构建会清理本实例的 Twig 编译缓存。

基础镜像固定为服务器缓存的 eLabFTW 镜像摘要，构建依赖从中科大 Alpine 镜像站取得。2026-10-14 网络检查发现 mihomo 所选节点拒绝连接，国内 HTTPS 站点可正常访问；本次没有修改 mihomo 或校园网认证配置。

完整验证说明及截图见 `docs/v3-wb-conditions-20261014/WB条件自动带入与Linux预览说明.md`。
