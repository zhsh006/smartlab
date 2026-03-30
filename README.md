# SmartLab v3

SmartLab v3 现在基于 V2 基座继续采用 Route B：

- 以 `eLabFTW` 作为 ELN / 审计 / 权限 / 签名底座
- 在其代码和数据模型上继续扩展 `Project / Sample / Inventory / Instrument / Review / Knowledge / AI`
- 最终交付一套统一系统，而不是外接 eLabFTW 的门户

当前仓库里已有的 Next.js 工作台和连接器代码，会被视为迁移期原型，而不是最终产品边界。

## 当前已落地

- Next.js 工作台壳层
- 项目 / 实验 / 审核 / 样本 / 库存 / 仪器 / 知识库入口页
- Prisma 统一科研对象模型
- SQLite 本地开发库
- eLabFTW / InvenTree 连接器骨架
- eLabFTW 实验同步接口占位
- demo seed 数据
- 当前目录内可运行的一套全新 eLabFTW 实例

## 当前策略

从现在开始，开发分两条线并行：

1. `fork-core`
保留并研究 eLabFTW 的核心能力：
- 实验记录
- 审计日志
- 历史版本
- 权限
- 电子签名
- 附件

2. `smartlab-modules`
在底座上新增：
- 项目中枢
- 样本实体
- 库存与批次
- 仪器与预约
- 导师审核
- 知识沉淀
- 分析与 AI

## 文档

- 路线说明见 [`docs/route-b.md`](/home/r730lab/smartlab-v3/docs/route-b.md)
- 模块边界见 [`docs/module-boundaries.md`](/home/r730lab/smartlab-v3/docs/module-boundaries.md)
- 本地 eLabFTW 说明见 [`infra/elabftw/README.md`](/home/r730lab/smartlab-v3/infra/elabftw/README.md)

## 本地运行

1. SmartLab 原型壳层

```bash
npm install
npm run prisma:generate
npm run prisma:push
npm run db:seed
npm run dev
```

打开 `http://localhost:3000`

2. 当前目录内的新 eLabFTW 实例

地址：`https://127.0.0.1:3158`

这个实例当前主要用于两件事：
- 研究和确认 eLabFTW 真实能力边界
- 在迁移期验证数据模型与实验流

## 开发说明

- 现有连接器仍保留，但后续只作为迁移工具，不作为最终架构核心
- 当前开发库使用 SQLite，便于本机快速跑通
- 进入正式改造阶段后，会逐步转向以 eLabFTW 代码底座为中心的仓库布局
