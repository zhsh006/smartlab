export type CaptureMode = "QUICK" | "STANDARD";

type PackSection = {
  title: string;
  fields: string[];
};

export type AssayPack = {
  key: string;
  title: string;
  category: string;
  summary: string;
  coverFields: string[];
  protocolSteps: string[];
  resultColumns: string[];
  quickMode: string[];
  standardMode: PackSection[];
  qcRules: string[];
  linkedObjects: string[];
  reagentDictionary: string[];
  exports: string[];
};

export const experimentLayers = [
  {
    title: "通用 eLabFTW 层",
    description:
      "保留自由记录、探索性实验和临时笔记，让实验室继续拥有灵活 ELN 能力。",
    points: [
      "自由正文、附件、图像、签名与版本历史",
      "适合探索性实验、杂项记录和非标准流程",
      "延续现有实验列表、审核与归档能力",
    ],
  },
  {
    title: "生物实验规范层",
    description:
      "进入 Benchling 风格的 Assay Pack，让 protocol、结果表、样本关联和质控规则一开始就被铺好。",
    points: [
      "结构化步骤、结果字段、图片位点和自动摘要",
      "样本、抗体、引物、试剂盒和细胞株统一关联",
      "快速模式用于日常记录，标准模式用于正式留档",
    ],
  },
] as const;

export const experimentEntryGroups = [
  {
    title: "通用记录",
    description: "保持空白页与自由区块，覆盖临时实验和探索过程。",
  },
  {
    title: "生物实验",
    description: "进入标准化 Assay Pack，减少新人从零开始组织记录。",
  },
  {
    title: "动物实验",
    description: "后续接入动物对象、分组、给药、观察表和伦理流程。",
  },
  {
    title: "细胞实验",
    description: "后续扩展细胞状态、传代、处理条件和成像结果联动。",
  },
  {
    title: "分子实验",
    description: "承接 qPCR、PCR、测序前处理和核酸质量控制。",
  },
] as const;

export const assayPackCapabilities = [
  "实验封面：实验类型、目的、课题、操作者、日期、样本来源",
  "标准 protocol 骨架：按步骤录入，不从空白页起写",
  "结构化结果表：固定字段而不是大段自由文本",
  "样本与试剂关联：把样本、抗体、引物、试剂盒拉进记录",
  "质控提示：缺失字段、异常结果和对照失败直接提醒",
  "实验后摘要：自动收敛成本次结论、成功状态和下步建议",
] as const;

export const captureModes: Record<
  CaptureMode,
  { title: string; description: string; traits: string[] }
> = {
  QUICK: {
    title: "快速模式",
    description: "压缩到最少必填项，适合 10 分钟内完成日常记录。",
    traits: [
      "只保留封面、关键参数、结果结论和异常说明",
      "默认折叠高级字段、原始批次和补充质控项",
      "适合新人先完成记录，后续再补全正式档案",
    ],
  },
  STANDARD: {
    title: "标准模式",
    description: "展开完整字段、质控与对象关联，适合正式留档和审核。",
    traits: [
      "完整 protocol、结果表、图像位点与批次信息",
      "强制关键对照、重复和异常记录",
      "支持后续统计、比较、复盘和结构化导出",
    ],
  },
};

export const assayPackCatalog = [
  {
    category: "分子实验",
    packs: ["qPCR", "ELISA", "Flow Cytometry"],
  },
  {
    category: "蛋白与成像",
    packs: ["Western Blot", "Immunofluorescence", "Immunohistochemistry"],
  },
  {
    category: "细胞功能",
    packs: ["Cell Viability", "Migration / Invasion"],
  },
] as const;

export const assayPacks: AssayPack[] = [
  {
    key: "qpcr",
    title: "qPCR",
    category: "分子实验",
    summary:
      "把 Ct 结果直接收成可比较的结构化数据，而不是散落在正文和 Excel 截图里。",
    coverFields: [
      "实验目的",
      "课题编号",
      "操作者",
      "实验日期",
      "样本来源",
      "逆转录批次",
    ],
    protocolSteps: [
      "准备样本与 RNA 质控",
      "配置逆转录体系并登记批次",
      "录入引物与扩增参数",
      "采集 Ct 结果并执行异常孔判定",
      "生成 Fold Change 与实验摘要",
    ],
    resultColumns: [
      "Sample ID",
      "Target",
      "Ct",
      "Reference Ct",
      "ΔCt",
      "ΔΔCt",
      "Fold Change",
    ],
    quickMode: [
      "样本编号、处理组、目标基因、内参基因",
      "Ct、Reference Ct、重复数、是否剔除异常孔",
      "阴性对照结果、熔解曲线判定、本次结论",
    ],
    standardMode: [
      {
        title: "样本信息",
        fields: [
          "样本编号",
          "组织/细胞来源",
          "处理组",
          "提取日期",
          "RNA 浓度",
          "A260/280",
          "逆转录批次",
        ],
      },
      {
        title: "引物信息",
        fields: [
          "目标基因",
          "内参基因",
          "引物编号",
          "扩增片段长度",
          "退火温度",
        ],
      },
      {
        title: "扩增设置",
        fields: ["试剂盒", "反应体系", "循环参数", "仪器型号"],
      },
      {
        title: "结果表",
        fields: [
          "Sample ID",
          "Target",
          "Ct",
          "Reference Ct",
          "ΔCt",
          "ΔΔCt",
          "Fold Change",
          "重复数",
          "异常孔剔除",
        ],
      },
    ],
    qcRules: [
      "阴性对照不得出现异常扩增",
      "重复孔 Ct 差值超阈值时提示复核",
      "熔解曲线需为单峰，否则标记为风险结果",
    ],
    linkedObjects: ["样本", "引物", "试剂盒", "仪器", "项目"],
    reagentDictionary: ["SYBR / Probe Master Mix", "逆转录试剂盒", "无 RNA 酶水"],
    exports: ["结构化结果表", "Fold Change 摘要", "实验结论与下步建议"],
  },
  {
    key: "western-blot",
    title: "Western Blot",
    category: "蛋白与成像",
    summary:
      "把样本、抗体、条带图像和灰度定量绑定在同一条实验记录里。",
    coverFields: [
      "实验目的",
      "项目",
      "操作者",
      "样本批次",
      "目标蛋白",
      "成像批次",
    ],
    protocolSteps: [
      "登记样本裂解与蛋白定量",
      "配置凝胶、电泳与转膜参数",
      "选择抗体并记录孵育条件",
      "上传原图与条带标注",
      "完成灰度归一化与结论摘要",
    ],
    resultColumns: ["Lane", "Target", "Band Size", "Gray Value", "Normalized", "Conclusion"],
    quickMode: [
      "样本编号、蛋白浓度、上样量、目标蛋白",
      "一抗/二抗、曝光方式、灰度归一化值",
      "原图上传、结果判定、是否重复成功",
    ],
    standardMode: [
      {
        title: "样本与蛋白提取",
        fields: ["样本编号", "裂解液", "蛋白浓度", "上样量", "变性条件"],
      },
      {
        title: "胶和转膜参数",
        fields: ["胶浓度", "跑胶条件", "转膜条件", "膜类型"],
      },
      {
        title: "抗体信息",
        fields: [
          "一抗名称",
          "货号",
          "宿主",
          "稀释度",
          "孵育条件",
          "二抗信息",
        ],
      },
      {
        title: "成像与定量",
        fields: [
          "曝光方式",
          "目标条带分子量",
          "内参",
          "灰度值",
          "归一化值",
        ],
      },
      {
        title: "图像区",
        fields: ["原图", "裁剪图", "条带位置标注"],
      },
    ],
    qcRules: [
      "内参与目标条带需同时提供",
      "曝光过饱和或背景过高时提示重新成像",
      "归一化值缺失时不允许提交正式结论",
    ],
    linkedObjects: ["样本", "抗体", "试剂盒", "膜材", "成像仪"],
    reagentDictionary: ["PVDF 膜", "ECL", "5% 脱脂奶粉", "BSA 封闭液"],
    exports: ["图片 + 灰度 + 结论摘要", "抗体使用记录", "复现实验建议"],
  },
  {
    key: "if",
    title: "Immunofluorescence",
    category: "蛋白与成像",
    summary:
      "让拍照、定量和对照记录一起完成，不再把 IF 当成一篇自由实验日记。",
    coverFields: [
      "实验目的",
      "样本类型",
      "处理条件",
      "操作者",
      "显微镜",
      "拍摄批次",
    ],
    protocolSteps: [
      "登记固定、封闭与透膜条件",
      "绑定一抗、二抗与染料",
      "填写拍摄参数并上传原图",
      "采集视野级定量结果",
      "生成背景与曝光一致性判断",
    ],
    resultColumns: ["Field", "Cell Count", "Positive %", "Mean Intensity", "Colocalization"],
    quickMode: [
      "样本类型、处理条件、固定方式、一抗/二抗",
      "显微镜参数、代表性图片上传、阳性比例",
      "背景判定、曝光一致性、本次结论",
    ],
    standardMode: [
      {
        title: "样本信息",
        fields: ["细胞/组织", "铺板密度", "处理条件", "固定方式"],
      },
      {
        title: "染色流程",
        fields: [
          "封闭液",
          "透膜条件",
          "一抗信息",
          "二抗信息",
          "DAPI",
          "避光时间",
        ],
      },
      {
        title: "成像参数",
        fields: ["显微镜型号", "物镜倍数", "激发波长", "曝光参数", "拍摄批次"],
      },
      {
        title: "图像上传区",
        fields: ["原图", "多通道图", "Merge 图"],
      },
      {
        title: "定量表",
        fields: [
          "视野编号",
          "细胞数",
          "阳性细胞比例",
          "平均荧光强度",
          "共定位系数",
        ],
      },
    ],
    qcRules: [
      "阴性对照必须记录",
      "背景过高或曝光不统一时标记为质控风险",
      "缺失代表性原图时不能生成正式摘要",
    ],
    linkedObjects: ["样本", "细胞株", "抗体", "染料", "显微镜"],
    reagentDictionary: ["封闭液", "Triton X-100", "DAPI", "抗荧光淬灭封片剂"],
    exports: ["图像与定量汇总", "视野级结果表", "异常曝光提醒"],
  },
  {
    key: "ihc",
    title: "Immunohistochemistry",
    category: "蛋白与成像",
    summary:
      "用病理式结构收敛切片、染色条件、评分和代表性图像，方便组间比较。",
    coverFields: [
      "实验目的",
      "组织编号",
      "切片批次",
      "操作者",
      "抗体",
      "评分方案",
    ],
    protocolSteps: [
      "登记样本固定、包埋与切片信息",
      "填写修复、封闭和显色条件",
      "上传低倍和高倍代表图",
      "完成阳性区域与 H-score 评分",
      "形成组间差异与复染建议",
    ],
    resultColumns: ["Sample", "Localization", "Positive %", "Intensity", "H-score", "IRS"],
    quickMode: [
      "组织编号、切片批次、抗原修复、一抗信息",
      "阳性区域、染色强度、H-score/IRS",
      "代表图上传、组间差异和复染建议",
    ],
    standardMode: [
      {
        title: "样本与切片",
        fields: ["组织编号", "固定液", "包埋批次", "切片厚度"],
      },
      {
        title: "染色条件",
        fields: [
          "抗原修复",
          "封闭条件",
          "一抗/二抗",
          "DAB 显色时间",
          "复染条件",
        ],
      },
      {
        title: "病理观察",
        fields: ["定位", "阳性区域", "染色强度", "背景情况"],
      },
      {
        title: "评分表",
        fields: ["阳性细胞比例", "染色强度", "H-score", "IRS 评分"],
      },
      {
        title: "图像区",
        fields: ["低倍图", "代表性高倍图", "局部放大图"],
      },
    ],
    qcRules: [
      "阳性区域与评分必须同时填写",
      "背景异常或切片质量差时提示复染",
      "低倍与高倍图都缺失时不允许归档",
    ],
    linkedObjects: ["组织样本", "抗体", "切片批次", "显色试剂", "病理评分方案"],
    reagentDictionary: ["柠檬酸修复液", "DAB", "苏木精", "封片剂"],
    exports: ["病理评分表", "图像摘要", "定位结论与复染建议"],
  },
];
