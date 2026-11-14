# SmartLab 学生流程测试与修复交接

更新于 2026-10-15。当前工作：用户要求模拟学生通过浏览器逐步点击全部流程，发现缺漏/bug 并修复；生成贴近实验文件格式的原始资料、分析结果和汇报图。用户提示剩余约 32% 额度，要求可由其他 AI 接续。

## 用户已经确认的原则

- 减轻学生负担，结构化信息沿用、只补本次变化；不加逐阶段审批、打卡或强制每实验一篇报告。
- 原始资料与整理后的汇报图应能连接；学生记录方便，导师查看方便。
- 本次测试数据全部为软件模拟，用户要求不能被解释为已经调用实体仪器。所有文件有 SIM/SIMULATED 标识，不能当作真实科研结果。
- 这轮是系统测试与修复；先前的三页 PPT 样稿已交付，暂不扩展 PPT。

## 项目与运行位置

- C 盘源码：`C:/项目汇总/AGI项目/smartlab/LabSmart`。
- 用户需求：项目上级 `SMARTLAB_V3_CONTEXT.md`、`SMARTLAB_V3_ACCEPTANCE.md`，结合后续用户简化要求。
- PHP/eLabFTW + Twig/JS/MySQL，不能按 Next.js 重写；根 AGENTS.md 是与实际栈不匹配的 Next.js 提示。
- 当前目标是服务器独立实例 `/home/h3clab/smartlab-workbench/LabSmart`，容器 `smartlab-workbench-web` / `smartlab-workbench-mysql`，3159 端口。
- 不修改旧 `/home/h3clab/smartlab-previews/*` 和 patent/v4/v5 实例。
- Windows 本机 Docker 未启动；不用为这次流程测试重新部署本机 Docker。
- 浏览器测试入口 `https://localhost:3161`：`.local/student-audit-proxy.py` 使用之前获准安装的本机 CA 签发证书，后端以经认证 SSH 取回的 `server-pinned.pem` 验证 TLS。没有跳过浏览器证书警告、没有修改信任库。代理仅绑定 127.0.0.1。
- 服务器原生地址证书自签名，IAB 会拒绝；不要点击跳过证书警告。
- 另一聊天“查找科研管理系统记录”（id `01a12e2d-d294-7a22-a30a-c3cf6c4e30d1`）正做网络/固定域名入口。不要覆盖其 .env、网络、隧道、站点 URL；本聊天只做应用及测试资料。

## 会话与恢复

- SSH 已通过已有主机密钥与用户此前提供的账号建立；交互工具 `.local/wb-session.py`，读取隐藏密码后按行接收 JSON。密码不要再写入报告或日志；已有会话可复用。需要重连时使用用户在本聊天提供的既有 SSH 账号，或请用户提供当前连接方式。
- 正在运行的统一终端 session：SSH `22293`；本机 TLS 代理 `58825`。新的 AI 不应假设跨聊天可直接使用这些 session ID，可以检查既有进程/3161 监听后重建连接。
- Python：`C:/Users/zhsh0/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe`。
- SSH 工具会从 `.local/ssh-check-runtime` 导入 paramiko。
- 学生演示凭据见 `docs/h3clab-deployment-20261015.md`，避免在工具输出重复打印密码。演示学生是 `preview.student@local.test`。
- 交互测试使用 in-app browser（通过 cua_repl），不要用 HTTP/API 代替“学生点击”的验收。
- `cua_repl` 已有绑定 `auditBrowser` (browser 2)、`studentTab` (tab 2)、`auditFs`、`auditDir`。新会话先读文档并列 tabs 再绑定，旧编号不作保证。

## 已完成（有当前运行截图）

证据目录 `docs/student-audit-20261015/`；进行中记录 `findings-in-progress.md`。

1. 学生登录，关闭 Remember me。
2. 通过工作台点击新建 WB：实验 **#14**，名称 `[模拟测试 E2E-1015] WB · 条件记录与原图`，关联现有演示课题 HPC-2026。
3. 选择入库的 Anti-GAD67（测试），自动带入“实验室标准 WB（测试）”；参考未被自动标为执行。
4. 将本次稀释比例改为 `1:2000`，填写 C01–C03/T01–T03 泳道与模拟气泡异常，保存阶段“抗体孵育”。返回工作台、再进入后草稿和阶段仍在。
5. 通过文件选择器上传 4 文件，WB 附件 **#19–22**：定量 CSV、16-bit TIFF、原图 PNG、Figure PNG。原始资料页计数 0→4。
6. 点击完成，WB 成功结束，未强制报告；实验 #14 完成状态与条件确认正确。
7. 通过页面新建 qPCR：实验 **#15**，名称 `[模拟测试 E2E-1015] qPCR · 96孔导入与续记`。保存样本与分组、TARGET_A/REF_A、模拟引物/试剂与 20 μL 模拟体系，进入“上机与 Ct”。
8. 上传 qPCR 文件 **#23** XLSX（README + Ct_Results 两工作表）、**#24** 96孔 Ct CSV、**#25** 96孔扩增曲线 CSV。
9. 服务在另一聊天的入口维护中重建；重新登录后实验 #15、条件、阶段与三个文件均保留。维护期短暂 502 不归因于产品保存失败。
10. Ct CSV 预览报错定位和修复后，已通过页面核对列对应并点击导入 **96 行**。Undetermined、No Ct、空值保持原样；未转换成 0。最后浏览器停在实验 #15 `&file=24`，已导入状态。

## 修复与部署

### A. 工作台进度误报（已部署，尚需最终 UI 回看）

记录页保存“抗体孵育”，工作台显示“待开始／样本与分组”。原因 `SmartlabDashboard::readBenchSummaries()` 仅查询 step.*，没取 wb.progress / wb.actual / wb.setup / assay.workspace。

已修改 `apps/elabftw/src/Models/SmartlabDashboard.php`：把这些记录取入进度摘要；confirmed_steps 仍只计 step.*，避免混算。部署到主机源文件和运行容器。修复 SHA-256 `2b4c16cbd327526279e2ef74ccaf6df4f71cb933ec64a5f4741b31bac0564764`。

### B. qPCR CSV 带空行时预览 TypeError（已部署并 UI 复测）

`str_getcsv('')` 返回 `[null]`，旧解析器 `normal(string)` 拒绝 null，页面只显示通用保存错误，列列表为空。C 盘较新文件已经包含 `normal(mixed)` 空行修复，但服务器重建回退到磁盘旧代码。本轮恢复 C 盘文件并同步主机磁盘，避免下次再次回退。

`apps/elabftw/src/Services/SmartlabQpcrImport.php` 已部署 SHA-256 `2402961e69b21f7c8716ab133f5dbbd316339be1a363b9ad34978404e3e00e02`。

### 验证与备份

- `tools/validate-student-journey.php`：事务回滚检查 WB/qPCR/IF 阶段摘要、草稿不冒充确认、CSV 尾部空行、精度和未知 Ct，已在候选代码及部署代码均通过。
- 服务器备份 `/home/h3clab/smartlab-workbench/LabSmart/runtime/backups/20261015-student-audit/`，包含 host/container 原文件与 `database.sql.gz`（测试记录创建之后、修复部署之前）。不要把它当作原始无测试数据生产备份。
- 本机备份 `.local/change-backups/20261015-student-audit/SmartlabDashboard.php.before`。
- 部署辅助 `.local/student-audit/backup-server.py`、`deploy-first-fixes.py`，有更改前字节比对，部署不会覆盖未经审阅的远端变动。
- **OPcache validate_timestamps=Off**。docker cp 后需要对 PHP-FPM master 发 USR2 平滑刷新，不能仅靠等两秒。上次 master PID 185 是当时观察值，后续必须重新查询，不要盲用 PID。
- 两文件已持久同步主机源目录，但整个镜像还没重建。最终应在不覆盖另一聊天 .env 的前提下统一构建一次；避免为了每个小修重复部署。
- 重建时发现服务器磁盘与起初运行镜像不完全一致，导航会回退（“我的空间”等入口减少）；最终交付前需要核对本机、主机源目录、运行镜像的相关功能文件，不能只检查容器。

## 测试资料已生成

`test-data/student-journey-20261015/`：**122 文件**（含 manifest），约 66.7 MB；ZIP `test-data/SmartLab_模拟科研测试数据_20261015.zip`（约65.2 MB）。

- 统一虚构 6 样本、两组各3个独立模拟单元；样本身份横跨 WB/qPCR/IF。
- WB：3曝光，16-bit TIFF、全膜 PNG、泳道表、从15s数组 ROI 定量的 CSV、PNG/PDF 汇报图；90s 故意过曝。
- qPCR：96孔 CSV/TSV/XLSX、RFU扩增和熔解信号、样本汇总与 PNG/PDF 图；XLSX 第一页 README，第二页 Ct_Results。
- IF：6样本×2视野×3通道，36个16-bit TIFF；同视野通道匹配的 RGB 预览、汇报面板；像素尺寸只是明确标记的模拟参数。
- ELISA：96孔 OD450、标准模拟模型曲线；IHC：人工明场细胞幻影。
- 负例：错误孔位、负数/巨大 Ct、重复孔、损坏 XLSX、空 CSV。
- 生成器 `tools/generate-student-test-data.py`，确定性随机种子10152026；NumPy/Pillow/ReportLab/openpyxl/pypdfium2，不依赖实体仪器。
- WB/qPCR/IF 汇报图已视觉检查。全包有 manifest SHA-256，后续上传后校验字节。

## 接下来（按优先级）

1. qPCR：复核当前导入，重复点击同文件导入应幂等；XLSX 工作表切换与损坏/空文件的友好错误；负数 Ct/错误孔位/重复孔不能静默当有效值。添加一条现场异常，上传分析与 Figure，结束而不强制报告。
2. IF：通过工作台新建带同前缀的实验，填写少量样本/染色资料，上传匹配通道 TIFF/PNG，补充样本/视野/通道来源，确认不同视野不会被自动混为 merge；上传 Figure，结束。
3. 免疫组化、ELISA：通过实际入口至少走一遍新建、记录、加文件、结束；如旧通用表单阻断低负担流程，要记录并做最小修复。
4. Agent：把一个带 SIM 标识的文件放进已配置模拟仪器导出目录 `/home/h3clab/smartlab-workbench/instrument-sim`，等待既有 Agent 上报；学生在“数据”页人工确认归属到本轮某一测试实验，验证原件保留和重复扫描不重复包。不要创建额外设备令牌、不删除源文件。服务现有配置用了 --insecure，应改成校验既有证书（不弱化安全），变更前先读配置并备份。
5. 汇报：学生主动创建7天/14天汇报，选整理后的 Figure；验证原始资料不会自动冒充 Figure，查看来源回到对应实验/原件。当前所有附件都列成“实验附件”，需要实测并修复分类/引用缺口，避免凭文件名推断科研结果。
6. 导师：用现有演示导师/管理员角色进入同一课题汇报，查看与反馈；学生看到反馈并能接后续记录。现有 PeriodReports `readOne` 仅本人条件可能导致导师打不开，需实际复现再改；不要只读源码就宣称验收通过。
7. 刷新/登出重登后检查实验、附件、导入、汇报；原文件 SHA-256核对；错误输入不覆盖已存版本。
8. 手机宽度与桌面关键页，修最明显溢出/不可读问题。当前导入预览表在桌面也会把数字拆行，截图10可见，宜设表格合理最小宽度和横向滚动。
9. 最终统一部署、复测，输出精简文字结果+截图+完整路径，补全本交接与 findings；尚未实现/无法检查的必须明确，不能声称全V3通过。

## 浏览器停留与截图

- 当前 URL `https://localhost:3161/smartlab-record.php?id=15&file=24`，Ct已导入。
- 最近 AX：`预览与导入` 按钮 #43；`后续分析` #35；补文件信息 #675；记变化异常 #684；结束按钮 #702。只是当时编号，下一步必须重新获取 DOM/AX。
- 截图09为失败预览；10为已修复列对应预览。07-full为WB结束全页，05为阶段误报前证据。其余依名称即可定位。
- 通过 `cua_repl` 截图存到证据目录后，应打开保存文件检查，不把历史截图当本轮新证据。

交接文件应随每轮真实完成进展更新；不写“已完成”来替代尚待操作的步骤。
