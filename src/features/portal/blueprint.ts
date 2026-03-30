export const operatingPillars = [
  {
    title: "统一数据模型",
    description:
      "实验、样本、试剂、仪器、结果、流程、人员和审批进入同一套对象体系，避免继续把科研资产拆成孤立表单。",
  },
  {
    title: "统一科研工作台",
    description:
      "围绕课题组每天真正会打开的任务组织界面，把计划、执行、审核、预约、分析和汇报放在一个工作流里。",
  },
  {
    title: "合规科研底座",
    description:
      "保留 eLabFTW 式审计日志、版本历史、电子签名、锁定归档和角色权限，把好写与可追溯同时做到位。",
  },
  {
    title: "AI-Ready 数据资产",
    description:
      "AI 不只读一篇记录，而是基于结构化项目上下文、历史实验、知识库和分析结果给出建议、总结和异常提醒。",
  },
] as const;

export const coreModules = [
  {
    href: "/experiments",
    eyebrow: "01",
    title: "实验记录中心",
    description:
      "块编辑器式 ELN，承载正文、步骤、图像、表格、结果块、模板、签名、修订和锁定归档。",
    outcome: "让实验记录既顺手可写，又能进入统一数据链路。",
  },
  {
    href: "/samples",
    eyebrow: "02",
    title: "样本与实体中心",
    description:
      "把样本、细胞株、抗体、患者批次等建成可引用、可流转、可统计的实体对象，而不是备注文本。",
    outcome: "建立跨实验、跨项目的对象追踪能力。",
  },
  {
    href: "/inventory",
    eyebrow: "03",
    title: "库存与耗材中心",
    description:
      "统一批号、有效期、位置、库存量、领用记录和实验消耗，把库存变化和实验执行真正挂上钩。",
    outcome: "从‘查库存’升级为‘知道实验消耗了什么’。",
  },
  {
    href: "/instruments",
    eyebrow: "04",
    title: "仪器与预约中心",
    description:
      "维护设备档案、预约排班、维护状态和实验关联，让仪器成为数据链路中的一等对象。",
    outcome: "把设备使用过程纳入项目节奏和审计轨迹。",
  },
  {
    href: "/reviews",
    eyebrow: "05",
    title: "流程与审核中心",
    description:
      "把实验提交、导师审核、返修、确认、归档，以及样本申请和销毁审批做成产品化流程。",
    outcome: "把口头协作变成可跟踪、可回溯、可统计的工作流。",
  },
  {
    href: "/analysis",
    eyebrow: "06",
    title: "数据分析中心",
    description:
      "承接原始结果、分析运行、图表摘要和阶段结论，不让分析留在离散脚本和聊天记录里。",
    outcome: "让结果能回连实验、样本、仪器和知识文档。",
  },
  {
    href: "/knowledge",
    eyebrow: "07",
    title: "知识沉淀中心",
    description:
      "把成功 protocol、失败案例、导师批注、参数优化轨迹和 FAQ 变成团队可继承的组织资产。",
    outcome: "形成越用越值钱的实验室知识网络。",
  },
  {
    href: "/assistant",
    eyebrow: "08",
    title: "AI 助手中心",
    description:
      "基于统一模型做实验总结、周报生成、相似条件推荐、知识检索和异常结果提醒。",
    outcome: "让 AI 成为科研执行系统的一部分，而不是外挂聊天框。",
  },
] as const;

export const operatingLoops = [
  "实验计划 -> 执行记录 -> 导师审核 -> 锁定归档",
  "样本建档 -> 流转使用 -> 审批销毁 -> 审计留痕",
  "库存入库 -> 批次追踪 -> 实验消耗 -> 低库存提醒",
  "预约排班 -> 仪器执行 -> 结果分析 -> 知识沉淀",
] as const;

export const complianceFoundation = [
  "审计日志",
  "版本历史",
  "电子签名",
  "锁定归档",
  "角色权限",
  "附件与引用关系",
] as const;
