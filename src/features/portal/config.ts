import type { NavigationItem } from "@/shared/types/navigation";

export const primaryNavigation: NavigationItem[] = [
  {
    href: "/workspace",
    label: "工作台",
    description: "统一科研操作系统首页与跨模块调度",
  },
  {
    href: "/projects",
    label: "项目",
    description: "课题总览、里程碑与统一科研上下文",
  },
  {
    href: "/experiments",
    label: "实验",
    description: "块编辑器式 ELN 与实验追踪入口",
  },
  {
    href: "/samples",
    label: "样本",
    description: "样本、实体对象与流转记录",
  },
  {
    href: "/inventory",
    label: "库存",
    description: "物料、批次、位置与消耗回写",
  },
  {
    href: "/instruments",
    label: "仪器",
    description: "设备档案、预约与维护",
  },
  {
    href: "/reviews",
    label: "流程",
    description: "导师审核、返修、审批和归档流转",
  },
  {
    href: "/analysis",
    label: "分析",
    description: "结果解析、运行状态与阶段结论",
  },
  {
    href: "/knowledge",
    label: "知识库",
    description: "SOP、FAQ、失败案例和阶段总结",
  },
  {
    href: "/assistant",
    label: "AI 助手",
    description: "总结、检索、建议与异常提醒",
  },
  {
    href: "/route-b",
    label: "Route B",
    description: "真实读取 eLabFTW 底座上的 SmartLab 扩展表",
  },
];
