# SmartLab 本机部署记录（2026-10-13）

项目位置：`C:\项目汇总\AGI项目\smartlab\LabSmart`。

完整系统：<https://localhost:3159/>。
新版工作台预览：<http://127.0.0.1:5180/>。
新版 WB 录入预览：<http://127.0.0.1:5180/wb-record.html>。

`preview-v1` 是独立的界面演示，使用模拟数据，保存提示也是演示，尚未接入后台。完整系统运行现有 PHP/eLabFTW 源码，本次没有实施新的产品简化方案。

## 部署问题与修复

| 问题 | 修复 |
| --- | --- |
| Dockerfile 跟随 `latest`，新镜像要求非 root 运行和内部 8080 端口，原配置仍使用旧方式 | 固定已验证的镜像摘要，设置 UID/GID 101、只读根目录、`/run` tmpfs，使用内部 8080 |
| SmartLab 扩展表尚未初始化，登录时报缺表 | 应用仓库现有 `database/elabftw/001_smartlab_phase1.sql` |
| 加密密钥仅 64 个十六进制字符，Defuse 无法解析 | 用镜像自带 Defuse 生成有效密钥，仅修正本机部署环境配置；原配置已经备份 |
| 原 HTTPS 证书没有有效 Subject/Issuer | 重建 localhost、127.0.0.1、::1 开发证书；经用户明确同意，根证书安装到 Windows 当前用户 Root |
| 新镜像 API 路由不再传递现有源码依赖的 `req` 参数，v2 请求误入 v1 处理器 | 在部署 Dockerfile 中为 v2 路由保留原始 URI；未修改业务 PHP 或前端功能 |
| 原健康检查只检测 nginx，无法发现 PHP 故障 | 本机覆盖配置检查真实 PHP 登录页 |
| 新镜像改变上传、临时文件、缓存和导出默认目录 | 同一份持久化目录同时挂载到现有源码与镜像使用的位置；`/tmp` 使用符号链接指向 PHP 实际临时目录，没有放宽 `open_basedir` |

官方版本迁移说明：<https://doc.elabftw.net/docs/edge/install/upgrade/update-6/>。

本机 Web 和 MySQL 发布端口分别绑定 `127.0.0.1:3159`、`127.0.0.1:3158`。本次使用本机独立数据库和上传目录，没有迁移或修改实验室服务器上的在线数据。

## 再次启动

先启动 Docker Desktop，再在 PowerShell 执行：

```powershell
& 'C:\项目汇总\AGI项目\smartlab\LabSmart\deploy\local\start-local.ps1'
```

源码或 Dockerfile 更新后，用同一个脚本加 `-Build` 重建镜像。脚本同时使用基础 Compose 文件和本机覆盖文件；不要仅启动基础配置而遗漏覆盖文件。

管理员沿用 `deploy/local/README.md` 记录的本机账号，没有重置管理员密码。测试学生账号凭据只保存在本机 `.local/deployment-checks/test-student.json`，文件权限限制为当前 Windows 用户和 SYSTEM。

## 已完成的验证

- Windows 正常校验 HTTPS 后访问登录页返回 200；内置浏览器通过 `https://localhost:3159` 正常打开，无须跳过证书警告。
- 管理员和本机测试学生均成功登录。
- 测试学生创建 WB 模板实验 #1，标题为“部署验证：WB 模板保存测试（非真实实验）”。
- 实验目的和样本分组草稿保存成功；刷新、容器重建后重新登录，仍能读取相同内容。
- 浏览器上传 `deployment-test.txt` 返回 201，附件出现在记录中，文件持久化到宿主机；原件与存储文件 SHA-256 相同。
- Web、MySQL 均健康；启动脚本运行通过。
- 与原始 ZIP 对比，`apps` 下业务源码差异为 0。

测试数据仅为本机部署验证用途。没有进行所有实验类型、导师审核、ImageJ、PDF 导出等全量功能验收。

完整系统验证截图：`.local/deployment-checks/system-record.png`。
新版界面预览截图：`.local/deployment-checks/design-preview.png`。

## 备份和证书

修复前的数据库、配置和原证书保存在：

`C:\项目汇总\AGI项目\smartlab\LabSmart\.local\deployment-backups\20261013-222204`。

修复前镜像保留为 `smartlab-local-before-fix:20261013-222204`。原源码 ZIP 未改动。

开发 CA 名称：`LabSmart Local Preview CA`。
安装位置：`Cert:\CurrentUser\Root`。
指纹：`72DD13180C89FAC35FB515A28BDD81A5C223B4AB`。
CA 限制在 localhost 与回环 IP 范围。CA 私钥位于 `.local/private-ca`，没有挂载进 Web 容器。

如果以后不再使用这套本机 HTTPS，可仅撤销这枚根证书：

```powershell
Remove-Item -LiteralPath 'Cert:\CurrentUser\Root\72DD13180C89FAC35FB515A28BDD81A5C223B4AB'
```

## 新版简化界面集成（2026-10-14 更新）

按用户确认，以《SmartLab_V3_汇报方案_18页.pdf》为需求基线，将新版页面接入真实系统（真实数据、真实保存），原版页面保留：

- 学生首页改为 V3“今天做什么”任务台（`dashboard.html`）：
  - 今天待做：由实验的“当前未确认步骤/待补充反馈”自动推导（`DashboardController::buildTaskboard`）；
  - 本周计划：读取 `smartlab_project_tasks`（当周截止/未完成），显示进度条；
  - 提醒：物料批次效期（`smartlab_material_lots.expires_at`）+ 近期截止任务；
  - 最近实验：实验卡片（当前阶段、最后记录、状态）。
- 新增“实验快速记录”页 `/smartlab-record.php?id=<实验ID>`：阶段进度条（绿=已确认、蓝=当前、浅蓝=下一步）、当前阶段参数卡、右侧时间线与底部文件上传；三个页签：快速记录 / 原始资料 / 完整档案。
- 保存沿用现有 `smartlab_bench_records` 机制：草稿自动保存、按方案确认、补录/纸质转录、步骤证据关联；完整档案页签展示“方案参考 vs 实际情况”与全部修改历史。
- 工作台“最后记录/当前阶段”由 `SmartlabDashboard::readBenchSummaries` 汇总（SQL 读取最新记录与已确认步骤）。
- 导航“实验工作台”已更名为“今日任务”。
- 注意：源码更新后必须用 `start-local.ps1 -Build` 重建启动；Dockerfile 已让容器启动时同时清理 Twig 编译缓存（`twig-*`），否则会继续渲染旧模板。
- 快速记录页按 V3 原型微调（2026-10-14）：WB 阶段细分为 6 段（样本与分组/上样与电泳/转膜/抗体孵育/显影曝光/完成记录）；异常情况改为勾选（条带较弱/背景偏高/非特异条带/上样损失/转膜气泡）+ 自由文本双向同步。旧阶段键（separation/acquire）的历史记录保留在时间线中，但不再映射到新阶段。
- 新增“数据”页 `/smartlab-data.php`（V3 Agent 接收端）：RAW→LINK→PARSED→ANALYSIS 分层说明；待关联数据包列表（文件名/大小/时间、关联到实验 confirm）；已归档数据表；“模拟 Agent 采集（演示）”按钮（本机生成模拟数据包，`smartlab_data_packages` 表）。
- 新增“周报/双周汇报”页 `/smartlab-reports.php`（V3 周报工作区）：周期选择（默认近 14 天）；本周期完成工作自动汇总（按实验记录活动）；关键 Figure 从本周期上传文件中选择（图片显示缩略图）；主要发现/存在问题/下周计划；保存草稿与提交冻结（`smartlab_period_reports` 表），历史汇报列表。
- 导航补齐：今日任务 / 实验 / 数据 / 周报汇报 / 课题 / 待办与反馈。
- Agent 采集端就绪（2026-10-14 晚）：
  - 服务端接入端点 `web/smartlab-agent.php`（设备令牌认证、独立于会话）：接收仪器电脑上报的文件包，存入 `/elabftw/agent-inbox`（宿主机 `.local/elabftw/agent-inbox`），进入待关联队列；
  - 数据页新增“设备接入（Agent）”面板：生成/查看设备令牌、最近上报时间；
  - 确认归档时，Agent 文件会走标准上传管线导入为该实验的附件（修复：先复制到 PHP 临时目录再走 `Uploads::create`）；
  - 多文件上传字段必须用 `files[]`（PHP 对同名多文件只保留最后一个，已修复 agent 脚本）；
  - Linux 端脚本 `tools/agent/smartlab-agent.py`（Python3 标准库）：目录监控、稳定判定、打包上传、状态去重、断网重试；含 `smartlab-agent.service` 与 README（部署到仪器电脑用）；
  - CA 证书已导出：`.local/private-ca/local-ca.crt.pem`（供仪器电脑校验 HTTPS）；
  - Web 端口改为监听全部网卡（`3159`），局域网地址 `https://172.18.21.53:3159` 已验证可达。
- 演示数据（2026-10-14）：课题“海马抑制性环路研究（HPC-2026）”、实验 1/2/3 关联课题、6 条本周任务、3 个物料批次（ECL 效期 3 天内）、4 张演示图（WB 条带 ×2、定量柱状图、IF 面板）已上传到实验 #1；Agent 数据包 4 个已按流程归档。
- 修复：`readBenchSummaries` 的 JSON 路径（`$.data.state` → `$.state`），"已确认步骤/当前阶段"判断在此之前始终为空。
- 验收截图：`docs/screenshots-20261014/`（01 首页任务台、02 快速记录、03 数据、04 周报）。
- 按 V3 路线图仍属后续阶段（未做）：真实 Agent/Gateway 采集端、仪器解析器（qPCR/WB/流式/显微镜）、Figure 库与论文素材库、RAW/Figure 长期沉积分层页。

