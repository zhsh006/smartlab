# SmartLab

LabSmart 基于 eLabFTW，面向师生的日常实验记录与导师协作。2026-10-12 版以实验记录、课题和待办为主入口，自动识别及检查作为按需展开的辅助工具。

当前版本与验证记录见 [`docs/release-notebook-20261012.md`](./docs/release-notebook-20261012.md)，本地源码与历史材料位置见 [`docs/local-project-location.md`](./docs/local-project-location.md)。

项目仓库创建时间记录见 [`PROJECT_CREATED_2025-06-02.md`](./PROJECT_CREATED_2025-06-02.md)。

说明：SmartLab 负责医学科研训练业务、专利功能页面、数据链路、算法服务适配和部署交付。

## 目录结构

- `apps/`：Web 应用源码与 SmartLab 定制代码。
- `deploy/`：本地和服务器部署文件。
- `database/`：SmartLab 数据库扩展 SQL。
- `tools/`：数据库初始化、数据导入和验收辅助脚本。
- `docs/`：架构、数据模型和研发说明。
- `figtest/`：专利图、运行截图、开发资料和证明材料。

## 核心功能

- 学生端实验项目和实验记录创建。
- 原始记录、仪器结果、实验图片和数据表上传归档。
- qPCR、ELISA、Western blot、免疫荧光等实验结果识别与展示。
- 实验事件序列构建和逻辑断点识别。
- 前后实验记录对比、跳步识别和缺失步骤推理。
- 实验模板补全和过程草稿生成。
- 导师端审核、反馈和闭环归档。
- 图像分析 worker 与算法服务健康检查。

## 本地运行

```bash
npm install
npm run elab:up
```

地址：`https://127.0.0.1:3159`

初始化 SmartLab 扩展表和种子数据：

```bash
npm run elab:phase1:apply
npm run elab:phase1:seed
```

停止容器：

```bash
npm run elab:down
```

## 开发说明

- SmartLab 的业务页面、控制器、模型和模板统一在 Web 应用源码内维护。
- 数据库扩展统一放在 `database/`。
- 部署镜像通过 `deploy/local/Dockerfile` 从本仓库源码构建。
- 审查员版 V2 自主研发证明材料位于 `figtest/`。
