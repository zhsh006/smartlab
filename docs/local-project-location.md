# 本地项目位置与来源

整理日期：2026-10-12。

## 开发目录

`C:\Users\zhsh0\Documents\LabSmart`

公开仓库：`https://github.com/zhsh006/smartlab.git`

本次起始提交：`473bae225d30293dc17f892833d75d856da0a4cf`

开发分支：`codex/simplify-workflow`

## 已移动的历史资料

以下资料从 `D:\下载文件夹汇总` 移入本项目的 `archives` 目录：

- `SmartLab_V2源码提交包_20260530`：V2 审查源码包；说明中注明核心算法有隐藏。
- `labsmart-desktop-client-0.1.0-20260512-015741.zip`：桌面客户端源码包，内部版本为 `0.1.0-mvp`。

`archives` 仅在本机保存，已通过 `.git/info/exclude` 排除，避免把历史材料混入此次代码改版。

## 完整在线版源码

当前访问入口：`http://172.18.21.142:3180/login.php`，页面标示 SmartLab V3。

已通过服务器会话核实：当前部署项目为 `/srv/smartlab-v5/labsmart-patent-2.3`，实际运行的 Web 容器为 `labsmart-patent-23-web`。项目目录与容器源码存在差异，本地以改版前运行容器的源码为基线。

完整应用源码已导入 `apps/elabftw`，包含 PHP 业务、模板及 Web 资源；数据库、原始附件、服务器环境配置继续保存在服务器。

本次版本：`notebook-20261012`（2026-10-12 师生工作流简化版）。服务器发布目录为 `/srv/smartlab-v5/releases/notebook-20261012`。旧界面标示 V3；磁盘部署名称中的 2.3、V5 是历史名称，不代表同一套版本编号。

部署、验证与回退说明见 `docs/release-notebook-20261012.md`。

