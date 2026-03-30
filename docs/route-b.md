# Route B

Route B 的目标不是“接入 eLabFTW”，而是“把 eLabFTW 作为底座，重构成 SmartLab v2”。

## 定义

最终用户只面对一套系统：

- 一个登录入口
- 一套导航
- 一套权限模型
- 一套实验、样本、库存、审批和知识体系

eLabFTW 不再是外部产品名词，而是底层能力来源。

## 保留什么

来自 eLabFTW 的保留资产：

- 实验记录模型
- 富文本与附件体系
- 审计日志
- 修订历史
- 权限控制
- 电子签名
- 资源预约的基础实现

这些能力已经成熟，不应该重写。

## 重写什么

SmartLab v2 需要新增或重构的模块：

- `Project Hub`
- `Sample Registry`
- `Inventory & Lots`
- `Instrument Center`
- `Review Workflow`
- `Knowledge Base`
- `Assay Analysis`
- `AI Assistant`

这些模块不是 eLabFTW 的强项，必须由 SmartLab v2 主导设计。

## 数据策略

核心原则是：

- 实验正文继续保留文档属性
- 样本、物料、批次、仪器、审批任务必须成为一等实体
- 审核、领用、预约、分析都必须是事件

换句话说：

- 文档负责表达
- 实体负责追踪
- 事件负责审计

## 仓库策略

从现在开始，仓库会分成三个层次：

1. `upstream-core`
用于研究和承接 eLabFTW 原始核心

2. `smartlab-domain`
定义项目、样本、库存、仪器、审核、知识和 AI 的领域模型

3. `smartlab-ui`
统一页面、导航、工作台和信息架构

## 迁移阶段

### 阶段 1

先保留现有 Next.js 壳层和连接器，用于验证页面与对象模型。

### 阶段 2

抽取 eLabFTW 代码结构，梳理必须保留的领域和表。

### 阶段 3

让 `Project / Sample / Review` 成为主导航与主流程。

### 阶段 4

把实验记录从“主产品”降为“核心模块”。

这一步完成后，SmartLab v2 才真正不是一个 ELN，而是实验室操作系统。
