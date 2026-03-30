# eLabFTW Core Map

这个文件用于明确 Route B 下哪些上游区域优先保留，哪些区域要重构。

上游源码位置：

- [`upstream/elabftw/src`](/home/r730lab/smartlab-v3/upstream/elabftw/src)
- [`upstream/elabftw/web`](/home/r730lab/smartlab-v3/upstream/elabftw/web)

## 优先保留

### 认证与权限

- [`upstream/elabftw/src/Auth`](/home/r730lab/smartlab-v3/upstream/elabftw/src/Auth)
- [`upstream/elabftw/src/Models/Users`](/home/r730lab/smartlab-v3/upstream/elabftw/src/Models/Users)

理由：

- 用户、团队、角色、权限是底座能力
- 这部分一旦重写，代价高且风险大

### 审计与修订

- [`upstream/elabftw/src/AuditEvent`](/home/r730lab/smartlab-v3/upstream/elabftw/src/AuditEvent)
- [`upstream/elabftw/web/revisions.php`](/home/r730lab/smartlab-v3/upstream/elabftw/web/revisions.php)

理由：

- 这是实验室系统最难补的合规能力之一
- SmartLab v2 不应该丢掉这部分成熟能力

### 实验记录核心

- [`upstream/elabftw/src/Models`](/home/r730lab/smartlab-v3/upstream/elabftw/src/Models)
- [`upstream/elabftw/web/experiments.php`](/home/r730lab/smartlab-v3/upstream/elabftw/web/experiments.php)
- [`upstream/elabftw/src/templates`](/home/r730lab/smartlab-v3/upstream/elabftw/src/templates)

理由：

- 这是 eLabFTW 最核心、最成熟的一块
- 需要研究后决定是保留页面逻辑还是只保留后端模型

### 资源与预约

- [`upstream/elabftw/web/scheduler.php`](/home/r730lab/smartlab-v3/upstream/elabftw/web/scheduler.php)
- [`upstream/elabftw/web/database.php`](/home/r730lab/smartlab-v3/upstream/elabftw/web/database.php)

理由：

- 这部分可作为仪器预约和资源引用的基础

## 优先重构

### 导航与信息架构

- [`upstream/elabftw/web/dashboard.php`](/home/r730lab/smartlab-v3/upstream/elabftw/web/dashboard.php)
- [`upstream/elabftw/web/index.php`](/home/r730lab/smartlab-v3/upstream/elabftw/web/index.php)

方向：

- 从“实验/数据库”双入口改成“项目/实验/样本/库存/仪器/审核/知识”统一导航

### 项目中枢

上游没有真正强项目中心，这一块 SmartLab v2 需要主导设计。

### 样本与批次

上游资源对象不等于严格的样本实体和批次实体。

方向：

- 增加样本类型、批次、流转事件、引用关系

### 审核流

上游有权限、审计、签名，但没有你需要的“导师审核中枢”。

方向：

- 建立提交、退回、通过、归档的明确状态机

### 知识与分析

上游并不覆盖：

- SOP 结构化沉淀
- assay 标准分析
- AI 助手

这部分应视为 SmartLab v2 的新增产品层。

## 第一批源码研究目标

接下来优先研究这几类文件：

1. 实验模型与控制器
2. 审计和修订
3. 用户与权限
4. 资源与预约
5. SQL 结构

只有把这几块弄清楚，后面才能判断是：

- 在上游表上直接扩展
- 还是新增 SmartLab v2 自己的领域表
