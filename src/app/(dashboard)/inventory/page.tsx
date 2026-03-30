import { SectionCard } from "@/shared/ui/section-card";

export default function InventoryPage() {
  return (
    <SectionCard
      title="库存中心"
      description="这里会接 InvenTree 的物料、批次、位置和交易历史。当前阶段先保留模块入口和数据边界。"
    >
      <div className="rounded-[1.5rem] border border-dashed border-stone-300 bg-stone-50/70 p-6 text-sm leading-7 text-stone-600">
        计划统一试剂、耗材、批号、有效期、库存量和实验消耗回写。
      </div>
    </SectionCard>
  );
}
