import Link from "next/link";
import { AssayPackNotebook } from "@/features/experiments/components/assay-pack-notebook";
import {
  getAssayPackContext,
  listExperiments,
} from "@/features/experiments/queries";
import { SectionCard } from "@/shared/ui/section-card";

export default async function ExperimentsPage() {
  const [experimentRows, assayPackContext] = await Promise.all([
    listExperiments(),
    getAssayPackContext(),
  ]);

  return (
    <div className="grid gap-6">
      <AssayPackNotebook context={assayPackContext} />

      <SectionCard
        title="通用记录与已建实验"
        description="现有实验记录继续保留，作为通用层和已创建实验的统一入口。"
      >
        <div className="space-y-4">
          {experimentRows.map((item) => (
            <Link
              key={item.id}
              href={`/experiments/${item.id}`}
              className="grid gap-3 rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5 md:grid-cols-[1fr_auto_auto]"
            >
              <div>
                <h3 className="font-semibold text-stone-900">{item.title}</h3>
                <p className="mt-2 text-sm text-stone-600">{item.project.name}</p>
              </div>
              <div className="rounded-2xl bg-white px-4 py-3 text-sm text-stone-700">
                来源：{item.sourceSystem ?? "LOCAL"}
              </div>
              <div className="rounded-2xl bg-stone-900 px-4 py-3 text-sm text-stone-50">
                {item.reviews[0]?.status ?? item.status}
              </div>
            </Link>
          ))}
        </div>
      </SectionCard>
    </div>
  );
}
