# SmartLab Workbench — h3clab 部署记录（2026-10-15）

## 1. 迁移结果

项目已从本机 C 盘迁移到 h3clab 服务器，**专用目录**：

```
/home/h3clab/smartlab-workbench/
├─ LabSmart/                     # 源码：eLabFTW 定制 + V3 页面 + Agent + 部署脚本
├─ preview-v1/                   # 早期静态设计预览（仅供参考）
├─ design/ prototype/            # 早期设计图（仅留档）
├─ README.md
├─ SmartLab_开发需求与实施规格_V1.0.md
├─ SMARTLAB_V3_CONTEXT.md        # ChatGPT/Codex 轮次的交接上下文
├─ SMARTLAB_V3_ACCEPTANCE.md     # ChatGPT/Codex 轮次的验收清单
└─ SmartLab_V1范围收缩对照表.md
```

- **未迁移**：`LabSmart/.local/`（Windows 本地 Docker 数据卷，约 437MB 演示数据）。服务器实例使用全新数据库 + 种子数据。
- **与既有实例的隔离**：见 `LabSmart/deploy/h3clab/README.md`。本实例使用独立 Compose 项目名、容器名、数据卷与端口，**不会**与 `smartlab-wb-preview*`、`smartlab-v4`、`/srv/smartlab-v5` 的 `labsmart-patent-*` 共用任何数据或端口。

## 2. 部署与访问

| 项 | 值 |
|---|---|
| Compose 项目 | `smartlab-workbench` |
| 容器 | `smartlab-workbench-web`、`smartlab-workbench-mysql` |
| 固定网页入口 | `https://smartlab-v2.sumhs.org/`（Cloudflare Tunnel；已通过登录、页面与已有附件读取验证） |
| 校内直连 | `https://172.18.21.142:3159`（自签名证书，含 172.18.21.142/localhost） |
| 管理员 | `smartlab.sysadmin@local.test` / `p8wi4PscFqhj5drxOBkobEdw` |
| 演示学生 | `preview.student@local.test` / `SmartLabDemo2026!` |
| 数据目录 | `runtime/{uploads,cache,exports,agent-inbox,certs}` + Compose 卷 `mysql-data` |
| 配置 | `deploy/h3clab/.env`（随机库密码 + `tools:genkey` 生成的有效密钥，权限 600） |
| 部署脚本 | `bash deploy/h3clab/deploy.sh`（可重复执行；首次自动初始化） |

已应用的数据库迁移：`001_smartlab_phase1` → `001b_v3_agent_inbox`（本次新增，显式建 Agent 收件箱表，保证 002 外键可用）→ `002_v3_ingest_safety` → `003_wb_conditions`。

## 3. Agent（真实采集端）

- 服务器上作为 **systemd 服务**运行：`smartlab-agent.service`（`systemctl status smartlab-agent`）
- 监控目录：`/home/h3clab/smartlab-workbench/instrument-sim`（当前用模拟仪器导出目录）
- 令牌：由管理员在「数据」页生成（设备 + 数据接收人）；令牌哈希存储，只显示一次
- 协议：Agent 计算每个文件的 `size/mtime_ns/sha256` 生成清单 + `request_id`，服务端校验后原件进入待关联队列；**人工确认归属**后才导入实验附件
- 实测链路：投放 2 个文件 → Agent 自动上报 `package #1` → 学生确认归属到实验 #1 → 实验附件 4 → 6

## 4. 演示数据与工具

| 内容 | 位置 |
|---|---|
| 课题 + 本周任务 + 物料批次（提醒）| `tools/seed-demo.php` |
| 演示图（WB 条带 ×2、定量柱状图、IF 面板）| `tools/demo-assets/` |
| 运行期建表（幂等）| `tools/warmup-runtime-tables.php` |
| 生成/重置 Agent 令牌（演示）| `tools/dev-create-agent.php` |
| 设置指定账号密码（演示）| `tools/dev-set-password.php` |
| Agent 程序（Linux/Windows 通用）| `tools/agent/smartlab-agent.py`（+ `.service`、README） |

## 5. 验证记录

- 页面：`dashboard.php`、`smartlab-record.php?id=1`、`smartlab-data.php`、`smartlab-reports.php` 均 200（局域网 curl + 浏览器截图）
- 附件：实验 #1 共 6 个附件（4 张演示图 + Agent 导入的 `Ct_results.csv`、`CFX96_quant_preview.png`）
- 周报：候选 Figure 6 个；`app/download.php` 返回 `200 image/png`（哈希校验通过）
- Agent：`systemctl is-active smartlab-agent` = active；上报/幂等/待关联/导入全链路通过
- 截图：`docs/h3clab-20261015/`（01 工作台、02 快速记录、03 数据、04 周报）

## 6. 注意

- **网络入口已更新**：已修复笔记本 `172.22.96.88` 与旧 Docker 网络重叠造成的回程问题，并部署固定网页入口及公网 SSH 备用通道。访问命令、配置及回滚方法见 [`network-access-review-20261015.md`](network-access-review-20261015.md)。

- **不要**修改或停止既有实例：`/home/h3clab/smartlab-previews/*`、`/home/h3clab/smartlab-v4`、`/srv/smartlab-v5` 的 `labsmart-patent-*` 容器。
- 服务器 **mihomo 已于 2026-10-15 修复**：原唯一节点失效（连接被拒）→ 换为 v2rayN 中的 5 个可用节点（3 Trojan + 2 Reality）并新增「自动选择」故障切换组；旧配置备份 `config.yaml.bak-20261014-164650`。
- 源码更新后：`cd /home/h3clab/smartlab-workbench/LabSmart && bash deploy/h3clab/deploy.sh`（会重建镜像；容器启动时自动清理本实例 Twig 缓存）。
- 后续仍待办（见 `SMARTLAB_V3_ACCEPTANCE.md`）：WB 抗体/方案库的完整校验、Figure 库与论文素材沉淀、qPCR/IF 专项完善、备份恢复演练。
