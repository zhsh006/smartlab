import { SectionCard } from "@/shared/ui/section-card";

export default function KnowledgePage() {
  return (
    <SectionCard
      title="知识库"
      description="用于沉淀 SOP、FAQ、失败案例、参数优化轨迹和阶段总结，后续再接 RAG。"
    >
      <div className="rounded-[1.5rem] border border-dashed border-stone-300 bg-stone-50/70 p-6 text-sm leading-7 text-stone-600">
        当前先作为模块占位，后续会引入知识文档与实验链接关系。
      </div>
    </SectionCard>
  );
}
