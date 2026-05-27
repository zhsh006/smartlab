# SmartLab v4

项目仓库创建时间记录见 [`PROJECT_CREATED_2025-06-02.md`](./PROJECT_CREATED_2025-06-02.md)。

这个仓库现在是一个以 `eLabFTW` 为代码基座的 SmartLab 项目。
代码、部署、数据库扩展和工具脚本已经按长期维护结构重新排列，不再保留迁移期的门户壳层。

## 目录结构

- [`apps/elabftw`](/home/h3clab/smartlab-v4/apps/elabftw)
  eLabFTW 源码基座和 SmartLab 对其的页面、控制器、模型定制。
- [`deploy/local`](/home/h3clab/smartlab-v4/deploy/local)
  本地 Docker 部署文件，包括 compose、Dockerfile 和本地部署 env。
- [`database/elabftw`](/home/h3clab/smartlab-v4/database/elabftw)
  直接扩展 eLabFTW MySQL 的 SQL 文件。
- [`tools/elabftw`](/home/h3clab/smartlab-v4/tools/elabftw)
  针对 eLabFTW 数据库执行扩展 schema 和 seed 的辅助脚本。
- [`docs/architecture`](/home/h3clab/smartlab-v4/docs/architecture)
  eLabFTW 基座保留范围、重构方向和 schema 草案文档。

## 核心原则

- 业务实现优先落在 eLabFTW 本体代码上，而不是外挂门户。
- 本地运行和交付都围绕 Docker 化 eLabFTW 实例组织。
- 项目、样本、审核等能力通过扩展 eLabFTW 页面、控制器和数据库实现。

## 文档

- eLabFTW 核心保留/改造地图见 [`docs/architecture/elabftw-core-map.md`](/home/h3clab/smartlab-v4/docs/architecture/elabftw-core-map.md)
- SmartLab 扩展表草案见 [`docs/architecture/phase1-schema-draft.sql`](/home/h3clab/smartlab-v4/docs/architecture/phase1-schema-draft.sql)
- 本地 eLabFTW 部署说明见 [`deploy/local/README.md`](/home/h3clab/smartlab-v4/deploy/local/README.md)

## 本地运行

```bash
npm install
npm run elab:up
```

地址：`https://127.0.0.1:3159`

如果需要把 SmartLab 扩展表打进 eLabFTW MySQL：

```bash
npm run elab:phase1:apply
npm run elab:phase1:seed
```

停止容器：

```bash
npm run elab:down
```

## 开发说明

- 所有页面与功能改造都应优先落在 [`apps/elabftw`](/home/h3clab/smartlab-v4/apps/elabftw)
- 容器镜像通过 [`deploy/local/Dockerfile`](/home/h3clab/smartlab-v4/deploy/local/Dockerfile) 从本仓库源码构建
- SmartLab 的数据库扩展统一放在 [`database/elabftw`](/home/h3clab/smartlab-v4/database/elabftw)
