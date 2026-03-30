# Module Boundaries

## eLabFTW Core

这一层原则上只保留和增强，不轻易推翻。

- Authentication
- Team membership
- Experiments
- Items / resources 的基础对象机制
- Attachments
- Revisions
- Audit trail
- Electronic signature

## SmartLab Domain

这一层是 SmartLab v2 的真正产品核心。

- Projects
- Samples
- Materials
- Material lots
- Inventory transactions
- Instruments
- Bookings
- Review workflows
- Knowledge documents
- Analysis runs

## SmartLab UI

这一层统一用户体验。

- Workspace
- Project overview
- Experiment detail with entity panels
- Review center
- Sample registry
- Inventory center
- Instrument center
- Knowledge center
- Assistant

## Transitional Integrations

现有连接器暂时保留，但只能扮演迁移工具角色：

- `src/server/connectors/elabftw`
- `src/server/connectors/inventree`

这些代码的存在是为了帮助过渡，不代表最终系统仍以“外部系统 API 集成”为核心。
