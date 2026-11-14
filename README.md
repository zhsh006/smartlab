# SmartLab 项目资料

目标存放位置：`C:\项目汇总\smartlab`。

请在上述 smartlab 文件夹内解压本压缩包；包内不再嵌套同名 smartlab 文件夹。

## 内容

- `SmartLab_开发需求与实施规格_V1.0.md`：26 章需求与实施规格，62 条验收用例，作为交给技术人员的主文档。
- `prototype/smartlab-preview.html`：此前的交互演示页面，可用浏览器打开；属于设计探索，尚未通过最终页面验收。
- `prototype/smartlab-ui-qa.js`：演示页面检查脚本，保留供技术人员参考；执行环境需由技术人员核对。
- `design/`：此前生成的两张 SmartLab 界面设计图。

## 当前状态

本包保存目前可取得的 SmartLab 设计与开发交接资料。演示界面不代表已完成正式系统；正式账号、数据库、文件服务和后端源码尚未在本次任务中开发。现有 Tingan LabSmart V2 的源码与生产数据没有包含在本包中，技术人员需按开发规格核对实际仓库和部署。

当前云端任务不能直接写入用户 Windows C 盘，解压到目标目录需要在本地完成。

## 部署（h3clab 服务器）

2026-10-15 起，本项目以 **h3clab** 服务器上的实例为准（本机 Windows 不再作为部署环境）：

- 服务器目录：`/home/h3clab/smartlab-workbench`
- 应用地址：`https://172.18.21.142:3159`（局域网）
- 部署说明：`LabSmart/deploy/h3clab/README.md`
- 部署与验证记录：`LabSmart/docs/h3clab-deployment-20261015.md`
- 源码更新后重建：`cd /home/h3clab/smartlab-workbench/LabSmart && bash deploy/h3clab/deploy.sh`

服务器上另有既有 SmartLab 实例（`smartlab-previews`、`smartlab-v4`、`/srv/smartlab-v5` 线上系统），本项目与它们使用不同目录、容器、数据库和端口，互不影响。
