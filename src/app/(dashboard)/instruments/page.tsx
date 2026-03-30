import { SectionCard } from "@/shared/ui/section-card";

export default function InstrumentsPage() {
  return (
    <SectionCard
      title="仪器中心"
      description="设备档案、预约、维护和实验关联将会在这里统一管理。"
    >
      <div className="rounded-[1.5rem] border border-dashed border-stone-300 bg-stone-50/70 p-6 text-sm leading-7 text-stone-600">
        第一阶段先保留实体与预约入口，后续补维护计划和冲突检查。
      </div>
    </SectionCard>
  );
}
