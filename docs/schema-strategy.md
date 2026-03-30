# Schema Strategy

这个文件定义 Route B 下数据库怎么改，不是一次性全量设计，而是先定扩展原则。

## 结论

eLabFTW 的下列表可以直接承接底座职责：

- `users`
- `teams`
- `users2teams`
- `experiments`
- `experiments_revisions`
- `experiments_changelog`
- `uploads`
- `team_events`
- `experiments_request_actions`
- `items`
- `items_request_actions`
- `storage_units`

但它们不能单独支撑 SmartLab v2 的完整领域。

## 直接保留

### 用户与团队

- `users`
- `teams`
- `users2teams`

理由：

- 这是成熟的认证、成员和权限边界
- 没必要在第一阶段重写

### 实验记录

- `experiments`
- `experiments_comments`
- `experiments_revisions`
- `experiments_changelog`

理由：

- 文档记录、修订、审计都已经成熟

### 附件

- `uploads`

理由：

- 文件与不可变属性是 ELN 核心能力

### 请求动作

- `experiments_request_actions`
- `items_request_actions`

理由：

- 这里已经有“向某用户发起 review / sign / archive / timestamp 请求”的基础形态
- 可以作为 SmartLab 审核流的过渡底座

### 预约

- `team_events`

理由：

- 这是现成的预约事件模型
- 后续可映射为仪器预约或资源预约

## 不直接复用为业务主表

### `items`

`items` 很强，但语义太宽。

它适合：

- 通用资源
- 可预约对象
- 可采购对象

它不适合直接承担：

- 样本主表
- 严格批次表
- 结构化库存交易主表

原因：

- 样本和批次需要明确类型、状态流转、数量单位、来源和销毁事件
- `items` 更偏“资源实体 + 元数据 JSON”

## SmartLab v2 新增表

建议新增一组 `smartlab_*` 表，而不是一开始就硬改上游主表。

### 项目域

- `smartlab_projects`
- `smartlab_project_members`
- `smartlab_project_entities`

职责：

- 让实验、样本、物料、仪器都能归属于项目

### 样本域

- `smartlab_samples`
- `smartlab_sample_types`
- `smartlab_sample_events`
- `smartlab_sample_links`

职责：

- 样本编码
- 样本类型
- 流转记录
- 与实验/附件/仪器关联

### 库存域

- `smartlab_materials`
- `smartlab_material_lots`
- `smartlab_inventory_transactions`

职责：

- 物料主数据
- 批号、效期、位置
- 入库、领用、转移、报废

### 仪器域

- `smartlab_instruments`
- `smartlab_instrument_links`

职责：

- 仪器档案
- 将仪器与 `items`/`team_events` 对齐

### 审核域

- `smartlab_review_flows`
- `smartlab_review_tasks`
- `smartlab_review_decisions`

职责：

- 明确提交、退回、通过、归档
- 不把“review 请求”只停留在轻量 request action

### 知识与分析域

- `smartlab_knowledge_docs`
- `smartlab_knowledge_links`
- `smartlab_analysis_runs`

职责：

- 结构化沉淀 SOP、失败案例和 assay 分析结果

## 扩展方式

第一阶段不建议直接修改 `experiments` 和 `items` 的核心列定义。

推荐方式：

1. 保持上游主表可升级
2. 新增 `smartlab_*` 扩展表
3. 通过外键把 `experiments.id`、`items.id`、`users.userid`、`teams.id` 连起来

这样做的好处：

- 上游升级成本更低
- 自己的领域边界更清楚
- 失败时更容易回滚

## 第一批落地建议

优先新增这些表：

1. `smartlab_projects`
2. `smartlab_project_members`
3. `smartlab_samples`
4. `smartlab_sample_events`
5. `smartlab_review_flows`
6. `smartlab_review_tasks`

原因：

- 这 6 张表已经足以把 eLabFTW 从“实验记录工具”推向“项目驱动系统”
