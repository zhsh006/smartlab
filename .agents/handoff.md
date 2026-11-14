# SmartLab 跨设备开发交接文档（Windows ↔ Mac）

> 最后同步时间：2026-11-14（代码基准推进至 2026-10-15 h3clab 部署与学生全流程审计版）
> 远端仓库：`https://github.com/zhsh006/smartlab.git`（分支：`main`）

---

## 一、项目简介与目录架构

**SmartLab（LabSmart）** 是面向医学科研训练场景的远程科研实验记录与导师协同管理系统，基于定制化 **eLabFTW（PHP 8.4 + Twig + JS + MySQL）** 构建，配套仪器目录自动上报 Agent、桌面同步盘与微信小程序端，支持 WB / qPCR / IF / ELISA / IHC 结构化记录、原始数据溯源归档、7/14 天周期汇报与导师审阅反馈。

```text
smartlab/
├── .agents/handoff.md                 # 跨设备交接主文档（Mac 端可直接 @.agents/handoff.md 接力）
├── SmartLab_开发需求与实施规格_V1.0.md # 26 章完整业务规格与 62 条验收用例
├── SMARTLAB_V3_CONTEXT.md             # V3 架构上下文说明
├── SMARTLAB_V3_ACCEPTANCE.md          # V3 验收清单
├── SmartLab_V1范围收缩对照表.md        # 低负担工作流收缩对照表
├── design/ · prototype/ · preview-v1/ # 早期视觉稿、静态交互原型与本地预览页
├── 汇报PPT/                           # 讲座宣传三页样稿 PPTX 与生成脚本
└── LabSmart/                          # 【核心工程目录】
    ├── SMARTLAB_AI_HANDOFF.md         # 2026-10-15 学生端全流程 E2E 审计与热修详细记录
    ├── apps/elabftw/                  # Web 核心源码（Smartlab* Controllers / Models / Services / Twig 模板）
    ├── database/elabftw/              # SmartLab 扩展表 SQL 迁移脚本
    ├── deploy/
    │   ├── h3clab/                    # 【当前主力】h3clab 服务器独立实例部署脚本（3159 端口）
    │   ├── local/                     # 本地 Docker Compose 开发部署配置
    │   └── network-access/            # 校园网与公网反向代理配置快照说明
    ├── tools/
    │   ├── agent/                     # 仪器自动上报端 (smartlab-agent.py) + 桌面同步盘 (smartlab-drive-app.py)
    │   ├── wechat-miniprogram/        # 微信小程序端源码
    │   ├── generate-student-test-data.py # 确定性模拟实验数据生成器（WB/qPCR/IF/ELISA/IHC）
    │   └── validate-*.php             # 自动化事务回滚验收脚本套件
    └── docs/                          # 部署记录、阶段审计报告与页面截图
```

---

## 二、环境依赖与运行 / 部署方式

> **架构红线**：后端核心栈为 `PHP / eLabFTW + Twig + MySQL`，**严禁按 Next.js 重写**（`LabSmart/AGENTS.md` 中的旧提示已过时）。

### 1. Mac 本地拉取与初始化
```bash
git clone https://github.com/zhsh006/smartlab.git
cd smartlab/LabSmart
```
- 如需在本地重新生成 66.7 MB 模拟实验测试集（122 个文件，随机种子 `10152026`）：
  ```bash
  python3 -m venv .venv && source .venv/bin/activate
  pip install numpy pillow reportlab openpyxl pypdfium2
  python3 tools/generate-student-test-data.py
  ```

### 2. 服务器部署（当前主力环境：`h3clab`）
2026-10-15 起，正式开发/演示环境运行在 **h3clab 服务器**（本机不再强依赖本地 Docker）：
- 服务器路径：`/home/h3clab/smartlab-workbench/LabSmart`
- 容器名称：`smartlab-workbench-web` / `smartlab-workbench-mysql`（局域网入口 `https://172.18.21.142:3159`）
- **注意隔离**：切勿修改或停止服务器上的旧实例（`/home/h3clab/smartlab-previews/*`、`smartlab-v4`、`/srv/smartlab-v5`）。
- **更新部署命令**：
  ```bash
  cd /home/h3clab/smartlab-workbench/LabSmart
  bash deploy/h3clab/deploy.sh
  ```
  *注：容器内 PHP OPcache 开启了 `validate_timestamps=Off`，若使用 `docker cp` 热修单文件，需向容器内 `php-fpm` master 进程发送 `USR2` 信号刷新缓存；完整交付建议走 `deploy.sh` 重建镜像（注意保护已有 `deploy/h3clab/.env`）。*

---

## 三、当前开发进度（截至 2026-10-15）

详见 [LabSmart/SMARTLAB_AI_HANDOFF.md](../LabSmart/SMARTLAB_AI_HANDOFF.md)：
1. **已完成模块**：
   - 学生实验台低负担记录（沿用历史结构化条件、仅补本次变更，不强制每实验写长报告）。
   - WB 条件自动带入、泳道计算器、多曝光原图与定量附件归档。
   - **已修复并验证**：
     - `SmartlabDashboard::readBenchSummaries()` 工作台阶段摘要漏读 `wb.progress / wb.actual / wb.setup / assay.workspace` 的问题；
     - `SmartlabQpcrImport.php` 遇到尾部空行 CSV 时 `str_getcsv('')` 触发 `TypeError` 的问题（96 孔 Ct CSV 导入已通过验证，`Undetermined` / 空值原样保留不转 `0`）。
   - 配套客户端：`smartlab-agent.py`（仪器导出监控上报）、`smartlab-drive-app.py`（桌面同步盘）、`wechat-miniprogram`（小程序端）已就位。

---

## 四、下一步待办清单（Mac 周末接力优先级）

1. **qPCR 导入健壮性收尾**：复核同一文件重复点击导入的幂等性；验证多 Sheet XLSX 切换及损坏文件/负数 Ct/重复孔位的友好报错提示。
2. **IF / IHC / ELISA 全流程走查**：新建免疫荧光（IF）多通道实验，验证不同视野不会被自动混并成同一张 Merge；走通 IHC 与 ELISA 最小闭环。
3. **仪器上报 Agent 联调**：向模拟导出目录投递带 `SIM` 标识的测试文件，验证学生在「数据」页手动归属到目标实验、原件保留及去重机制，并将 `--insecure` 改为校验既有证书。
4. **周期汇报（7天/14天）与导师审阅闭环**：修复附件分类区分（原始资料不自动冒充整理后 Figure），排查并修复 `SmartlabPeriodReports::readOne` 仅限本人读取导致导师角色打不开汇报的问题。
5. **移动端与桌面窄屏表格体验**：为 qPCR 导入预览表增加合理 `min-width` 与横向滚动容器，解决数字折行问题。

---

## 五、未纳入 Git 的本地文件与环境变量说明

出于安全与体积控制（单文件 < 50MB），以下内容已被 `.gitignore` 排除，跨设备时按需配置：

1. **环境变量文件（`.env`）**：
   - `LabSmart/.env` 及 `LabSmart/deploy/local/.env`（本地 Docker 用）：包含 `ELABFTW_DB_HOST`、`ELABFTW_DB_PORT`、`ELABFTW_DB_NAME`、`ELABFTW_DB_USER`、`ELABFTW_DB_PASSWORD`、`MYSQL_ROOT_PASSWORD`、`MYSQL_PASSWORD`、`ELABFTW_SECRET_KEY`。
   - 服务器端 `deploy/h3clab/.env` 由 `deploy/h3clab/deploy.sh` 首次运行自动生成随机密钥（权限 `600`），保存在服务器上，勿用本地空配置覆盖。
2. **本地证书与调试运行态（`LabSmart/.local/`）**：
   - 包含 Windows 本机自签 CA（`.pem` / `.key`）、SSH 隧道辅助脚本及旧版备份快照，不入库。
3. **大体积模拟测试数据与源码快照包**：
   - `LabSmart/test-data/`（约 66.7 MB 模拟 TIFF/XLSX/CSV 及 62.2 MB 压缩包）：在 Mac 上直接运行 `python3 LabSmart/tools/generate-student-test-data.py` 即可按相同随机种子 `10152026` 100% 复现。
   - `LabSmart-source-20261013-193506-aae902f.zip`（47.2 MB 历史快照包）：对应 Git 历史提交 `aae902f`，已由 Git 提交历史完整保留。
