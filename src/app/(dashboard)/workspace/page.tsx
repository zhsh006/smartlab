import { getWorkspaceSummary, listExperiments } from "@/features/experiments/queries";
import { ElabftwSyncPanel } from "@/features/integrations/components/elabftw-sync-panel";
import {
  complianceFoundation,
  coreModules,
  operatingLoops,
} from "@/features/portal/blueprint";
import { SectionCard } from "@/shared/ui/section-card";
import { StatCard } from "@/shared/ui/stat-card";
import Link from "next/link";

export default async function WorkspacePage() {
  const [summary, experiments] = await Promise.all([
    getWorkspaceSummary(),
    listExperiments(),
  ]);

  const reviewQueue = experiments.slice(0, 3).map((item) => item.title);
  const alerts = [
    `低库存批次 ${summary.lowStockLots} 个`,
    `今日仪器预约 ${summary.bookings} 条`,
    `返修实验 ${summary.reviews} 条`,
  ];
  const highlightedModules = coreModules.slice(0, 6);

  return (
    <div className="grid gap-6">
      <section className="grid gap-4 md:grid-cols-4">
        <StatCard label="在研项目" value={String(summary.projects)} hint="已关联到统一项目上下文" />
        <StatCard label="待审核实验" value={String(summary.experiments)} hint="来自统一实验中心的审核任务" />
        <StatCard label="低库存预警" value={String(summary.lowStockLots)} hint="后续会接入 InvenTree 实时库存" />
        <StatCard label="今日预约" value={String(summary.bookings)} hint="当前来自本地仪器预约记录" />
      </section>

      <section className="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
        <SectionCard
          title="LabSmart 当前目标"
          description="这一页不只展示几个数字，而是把实验室每天的执行闭环组织成统一操作面。"
        >
          <div className="space-y-4 text-sm leading-7 text-stone-700">
            <p>
              当前原型已经具备统一对象模型、项目上下文和基础审核能力。接下来的重点不是继续堆表单，而是把实验、样本、库存、仪器、流程、分析、知识和 AI 收敛成一套连续工作流。
            </p>
            <p>
              简单说，底层按 Benchling 的对象思路建模，中层按 Labguru 的日常工作台组织，前台保留 eLabFTW 的合规记录能力。
            </p>
          </div>
        </SectionCard>

        <SectionCard
          title="日常运行闭环"
          description="科研系统应该服务真实操作节奏，而不是单页录入。"
        >
          <ul className="space-y-3 text-sm text-stone-700">
            {operatingLoops.map((item) => (
              <li
                key={item}
                className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3"
              >
                {item}
              </li>
            ))}
          </ul>
        </SectionCard>
      </section>

      <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
        <SectionCard
          title="审核与归档队列"
          description="后续这里会接入 eLabFTW 实验同步后的统一审核任务。"
        >
          <ul className="space-y-3 text-sm text-stone-700">
            {reviewQueue.map((item) => (
              <li
                key={item}
                className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3"
              >
                {item}
              </li>
            ))}
          </ul>
        </SectionCard>

        <SectionCard
          title="实验室提醒"
          description="库存、预约、分析与周报任务会在这里聚合。"
        >
          <ul className="space-y-3 text-sm text-stone-700">
            {alerts.map((item) => (
              <li
                key={item}
                className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3"
              >
                {item}
              </li>
            ))}
          </ul>
        </SectionCard>
      </div>

      <SectionCard
        title="核心模块蓝图"
        description="先把操作系统骨架搭对，后续再逐步把每个中心从占位页替换成真实业务界面。"
      >
        <div className="grid gap-4 xl:grid-cols-3">
          {highlightedModules.map((module) => (
            <Link
              key={module.href}
              href={module.href}
              className="rounded-[1.5rem] border border-stone-200 bg-stone-50/80 p-5 transition hover:-translate-y-0.5 hover:border-stone-400 hover:bg-white"
            >
              <div className="text-xs font-semibold tracking-[0.22em] text-stone-500 uppercase">
                {module.eyebrow}
              </div>
              <h3 className="mt-3 text-lg font-semibold text-stone-900">
                {module.title}
              </h3>
              <p className="mt-3 text-sm leading-6 text-stone-600">
                {module.description}
              </p>
            </Link>
          ))}
        </div>
      </SectionCard>

      <section className="grid gap-6 lg:grid-cols-[1fr_0.9fr]">
        <SectionCard
          title="合规科研底座"
          description="这些能力不是附加功能，而是整个系统可信性的基础。"
        >
          <div className="grid gap-3 sm:grid-cols-2">
            {complianceFoundation.map((item) => (
              <div
                key={item}
                className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3 text-sm text-stone-700"
              >
                {item}
              </div>
            ))}
          </div>
        </SectionCard>

        <SectionCard
          title="下一步产品重点"
          description="优先把最能体现差异化的系统层打通。"
        >
          <ul className="space-y-3 text-sm text-stone-700">
            <li className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3">
              先把实验入口升级为 Assay Pack 双层体系，再把 qPCR、WB、IF、IHC 做成标准化实验包。
            </li>
            <li className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3">
              把样本、物料批次、抗体、引物和仪器档案推进为可关联的一等对象。
            </li>
            <li className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3">
              把导师审核扩展成按实验包校验的模板化工作流，而不是只显示实验审核列表。
            </li>
          </ul>
        </SectionCard>
      </section>

      <ElabftwSyncPanel projectCode="SLV2-001" />
    </div>
  );
}
