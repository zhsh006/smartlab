# SmartLab Agent（仪器电脑数据采集端）

在仪器电脑或文件接收服务器上运行，监控指定导出目录；文件稳定后自动打包上报到 SmartLab 的"数据"页
待关联队列。**不修改、不删除、不移动源文件**；归属判断始终由人在网页上确认（宁可待关联，也不自动错配）。

## 工作原理

1. `--watch` 目录里出现新文件（或文件内容变化）；
2. 文件在 `--stable-seconds` 秒内大小与修改时间不再变化，认为写出完成；
3. 一批稳定文件作为一个"数据包"上传到 `https://<SmartLab 地址>/smartlab-agent.php`（设备令牌认证）；
4. 服务端存入待关联队列，学生在"数据"页确认归档后，原始文件会导入对应实验的附件。

已上报的文件记录在 `--state` 状态文件中（路径+大小+修改时间+sha256），不会重复上传；
网络失败自动重试，源目录断网期间照常工作。

## 快速开始（在仪器电脑上）

1. 在 SmartLab「数据」页生成一个接入令牌（填设备名称，如 `CFX96-01 仪器电脑`）。
2. 把本目录复制到仪器电脑，例如 `/opt/smartlab-agent/`：
   - `smartlab-agent.py` 主程序（Python 3.8+，仅标准库）
   - `ca.pem`（可选：SmartLab 的 CA 证书，用于校验 HTTPS；不提供时加 `--insecure` 演示）
3. 先手工试跑一轮（`--once`）：

```bash
python3 /opt/smartlab-agent/smartlab-agent.py \
  --server https://172.18.21.53:3159 \
  --token <你的令牌> \
  --name "CFX96-01 仪器电脑" \
  --watch /srv/instrument-export \      # 仪器软件的结果导出目录（可重复指定）
  --state /var/lib/smartlab-agent/state.json \
  --ca /opt/smartlab-agent/ca.pem \
  --once -v
```

4. 确认无误后装成常驻服务（见下）。

## systemd 常驻（Linux）

```bash
sudo mkdir -p /var/lib/smartlab-agent
sudo cp smartlab-agent.service /etc/systemd/system/
sudo systemctl edit --full smartlab-agent.service   # 填入 token / watch 目录 / 名称 / CA
sudo systemctl daemon-reload
sudo systemctl enable --now smartlab-agent
journalctl -u smartlab-agent -f
```

## 参数说明

| 参数 | 说明 |
|---|---|
| `--server` | SmartLab 地址（局域网 https，如 `https://172.18.21.53:3159`） |
| `--token` | 数据页生成的设备令牌 |
| `--name` | 设备/Agent 显示名（会作为数据包来源显示） |
| `--watch` | 要监控的导出目录，可写多个 |
| `--stable-seconds` | 文件"稳定"判定时间，默认 8 秒；正式可按仪器调成 30 秒 |
| `--interval` | 扫描间隔，默认 10 秒 |
| `--state` | 状态文件路径（记录已上传文件，防重复） |
| `--ca` / `--insecure` | HTTPS 校验方式；生产建议复制 CA 证书用 `--ca` |
| `--once` | 只扫描一轮后退出（测试/定时任务用） |
| `--max-files` | 单个数据包最大文件数，默认 200 |

## 注意

- 同一批扫描的文件会作为一个包上传；不同批次/目录的文件会自动分开。
- 建议 `--watch` 只指向仪器自身的导出目录，不要指向人工整理的目录。
- 大文件（>2GB 单个）暂不支持（v1）；当前实现整包读入内存，适合常规仪器结果（≤100MB 级别）。
- 卸载：`systemctl disable --now smartlab-agent` 即可，不会影响源目录文件。
# V3 本机第一批更新（2026-10-14）

请同步使用更新后的 `smartlab-agent.py`。新版先计算源端 SHA-256 和文件清单，服务端比对后保存；同名文件分别保存，重复请求复用同一数据包。源文件不移动、不修改、不删除。

旧设备令牌继续有效。管理员创建新设备时选择数据接收人，令牌只在创建成功时显示一次；普通学生不再接收设备配置或令牌。

已有本地 V3 数据库升级使用 `tools/apply-v3-safety.ps1`，启动脚本也会执行同一幂等迁移。该迁移需要既有 `smartlab_agents` / `smartlab_data_packages` 表，不负责从空库安装整个系统。

本批还没有完成断网持久队列、一个 Run 多实验 LINK 或关联更正历史，不能视作完整 Agent MVP 验收通过。

