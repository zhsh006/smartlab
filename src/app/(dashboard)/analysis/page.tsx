import { db } from "@/server/db/client";
import { SectionCard } from "@/shared/ui/section-card";

export default async function AnalysisPage() {
  const [runs, completedRuns, linkedExperiments] = await Promise.all([
    db.analysisRun.count(),
    db.analysisRun.count({
      where: { status: "SUCCESS" },
    }),
    db.analysisRun.count({
      where: {
        experimentId: {
          not: null,
        },
      },
    }),
  ]);

  const analysisBoards = [
    {
      title: "结果回连实验",
      description: "每次分析运行都应能回到原始实验记录、样本引用和设备上下文。",
      value: `${linkedExperiments}/${runs || 1}`,
    },
    {
      title: "运行状态可见",
      description: "从待处理、运行中到成功失败，要成为团队共享视图，而不是个人脚本黑盒。",
      value: `${completedRuns} 已完成`,
    },
    {
      title: "阶段结论沉淀",
      description: "分析摘要要能进入项目周报、知识文档和导师汇报，不再额外重复整理。",
      value: "项目级输出",
    },
  ];

  return (
    <div className="grid gap-6">
      <SectionCard
        title="数据分析中心"
        description="这一层承接实验执行后的结果解析、分析运行和阶段输出，让科研结果回到系统主链路中。"
      >
        <div className="grid gap-4 lg:grid-cols-3">
          {analysisBoards.map((item) => (
            <article
              key={item.title}
              className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5"
            >
              <div className="text-sm font-medium text-stone-500">{item.title}</div>
              <div className="mt-3 text-3xl font-semibold text-stone-900">
                {item.value}
              </div>
              <p className="mt-3 text-sm leading-6 text-stone-600">
                {item.description}
              </p>
            </article>
          ))}
        </div>
      </SectionCard>

      <SectionCard
        title="这一中心后续要承接什么"
        description="不是只放图表，而是把分析运行和科研对象重新连起来。"
      >
        <div className="grid gap-4 lg:grid-cols-2">
          <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5 text-sm leading-7 text-stone-700">
            1. 原始结果文件、分析脚本输出、图表摘要和关键参数进入统一记录。
            <br />
            2. 分析结果与实验、样本、仪器、库存批次形成双向引用。
            <br />
            3. 阶段结论直接进入知识库、项目汇报和 AI 总结上下文。
          </div>
          <div className="rounded-[1.5rem] border border-dashed border-stone-300 bg-stone-50/60 p-5 text-sm leading-7 text-stone-600">
            当前数据库里已经有 <code>AnalysisRun</code> 模型，下一步可继续补分析详情页、结果摘要卡片和项目级汇总视图。
          </div>
        </div>
      </SectionCard>
    </div>
  );
}
