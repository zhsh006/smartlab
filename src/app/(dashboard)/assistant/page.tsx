import { SectionCard } from "@/shared/ui/section-card";

export default function AssistantPage() {
  return (
    <SectionCard
      title="AI 助手"
      description="AI 不直接对散乱文本瞎回答，而是建立在项目、实验、样本、库存和知识文档统一模型之上。"
    >
      <div className="rounded-[1.5rem] border border-dashed border-stone-300 bg-stone-50/70 p-6 text-sm leading-7 text-stone-600">
        第二阶段接入知识检索与阶段总结，第三阶段再做实验分析和相似实验建议。
      </div>
    </SectionCard>
  );
}
