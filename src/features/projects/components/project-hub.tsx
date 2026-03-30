import Link from "next/link";
import { SectionCard } from "@/shared/ui/section-card";
import { StatCard } from "@/shared/ui/stat-card";
import { cn } from "@/shared/lib/utils";
import type { ProjectCard, ProjectHubData } from "@/features/projects/queries";

function formatDate(value: Date | null) {
  if (!value) {
    return "待补充";
  }

  return new Intl.DateTimeFormat("zh-CN", {
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).format(value);
}

export function ProjectHub({
  projects,
  hub,
}: {
  projects: ProjectCard[];
  hub: ProjectHubData | null;
}) {
  if (!hub) {
    return (
      <SectionCard
        title="项目中枢"
        description="当前还没有项目数据，先补项目主实体，再把样本、库存和流程挂进来。"
      >
        <p className="text-sm leading-7 text-stone-600">
          V3 不再从零散入口页开始，而是从项目上下文组织真实工作流。
        </p>
      </SectionCard>
    );
  }

  return (
    <div className="grid gap-6">
      <SectionCard
        title="Project Hub"
        description="先让项目成为真实中枢，再把实验、样本、库存、仪器、审核与知识作为连续工作面挂上来。"
      >
        <div className="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
          <div className="space-y-4">
            <div>
              <div className="text-xs font-semibold tracking-[0.22em] text-stone-500 uppercase">
                {hub.code}
              </div>
              <h2 className="mt-3 text-3xl font-semibold text-stone-900">
                {hub.name}
              </h2>
              <p className="mt-3 max-w-3xl text-sm leading-7 text-stone-600">
                {hub.description ?? "这个项目还缺少问题定义和执行说明，V3 会把这里作为项目主叙事层。"}
              </p>
            </div>
            <div className="flex flex-wrap gap-2 text-xs">
              <span className="rounded-full border border-stone-300 bg-stone-100 px-3 py-1.5 font-medium text-stone-700">
                状态 {hub.status}
              </span>
              <span className="rounded-full border border-stone-300 bg-stone-100 px-3 py-1.5 font-medium text-stone-700">
                成员 {hub.memberCount}
              </span>
              <span className="rounded-full border border-stone-300 bg-stone-100 px-3 py-1.5 font-medium text-stone-700">
                文档 {hub.documentCount}
              </span>
            </div>
            <div className="grid gap-3 md:grid-cols-2">
              {hub.members.map((member) => (
                <div
                  key={member.id}
                  className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3"
                >
                  <div className="text-sm font-semibold text-stone-900">{member.name}</div>
                  <div className="mt-1 text-xs text-stone-500">
                    {member.projectRole} · {member.role}
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="grid gap-3">
            <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/70 p-5">
              <div className="text-sm font-semibold text-stone-900">当前执行重点</div>
              <ul className="mt-4 space-y-3 text-sm leading-6 text-stone-700">
                <li>先打通项目下的实验、样本和流程关系，而不是继续扩散入口页。</li>
                <li>把批次预警和预约状态提到项目面板，形成真实调度视图。</li>
                <li>保留 eLabFTW 的实验合规能力，但不再让它主导主导航。</li>
              </ul>
            </div>
            <div className="rounded-[1.5rem] border border-stone-200 bg-white p-5">
              <div className="text-sm font-semibold text-stone-900">导航建议</div>
              <p className="mt-3 text-sm leading-6 text-stone-600">
                V3 的项目页应该成为默认落点，后面其他中心页更多承担筛选和专门作业，而不是承担主上下文。
              </p>
              <Link
                href={`/projects/${hub.id}`}
                className="mt-4 inline-flex rounded-full bg-stone-900 px-4 py-2 text-sm font-medium text-stone-50 transition hover:bg-stone-700"
              >
                进入项目详情
              </Link>
            </div>
          </div>
        </div>
      </SectionCard>

      <section className="grid gap-4 md:grid-cols-4">
        <StatCard label="实验队列" value={String(hub.pendingExperimentCount)} hint="待提交和待返修实验" />
        <StatCard label="样本实体" value={String(hub.sampleCount)} hint="已进入项目上下文的样本数量" />
        <StatCard label="低库存批次" value={String(hub.lowStockLotCount)} hint="优先暴露会影响执行的库存风险" />
        <StatCard label="活动流程" value={String(hub.activeWorkflowCount)} hint="正在推进中的审批与工作流" />
      </section>

      <SectionCard
        title="项目索引"
        description="这里不是项目列表终点，而是进入各项目工作台的切换条。"
      >
        <div className="grid gap-4 xl:grid-cols-3">
          {projects.map((project) => {
            const isActive = project.id === hub.id;

            return (
              <Link
                key={project.id}
                href={`/projects/${project.id}`}
                className={cn(
                  "rounded-[1.5rem] border p-5 transition hover:-translate-y-0.5",
                  isActive
                    ? "border-stone-900 bg-stone-900 text-stone-50"
                    : "border-stone-200 bg-stone-50/70 text-stone-900 hover:border-stone-400 hover:bg-white",
                )}
              >
                <div className="text-xs font-semibold tracking-[0.22em] uppercase">
                  {project.code}
                </div>
                <div className="mt-3 text-lg font-semibold">{project.name}</div>
                <p
                  className={cn(
                    "mt-3 text-sm leading-6",
                    isActive ? "text-stone-300" : "text-stone-600",
                  )}
                >
                  {project.description ?? "待补充项目背景。"}
                </p>
                <div
                  className={cn(
                    "mt-4 grid grid-cols-3 gap-2 text-xs",
                    isActive ? "text-stone-300" : "text-stone-500",
                  )}
                >
                  <div>实验 {project.experimentCount}</div>
                  <div>样本 {project.sampleCount}</div>
                  <div>流程 {project.workflowCount}</div>
                </div>
              </Link>
            );
          })}
        </div>
      </SectionCard>

      <div className="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <SectionCard
          title="实验与审核"
          description="实验不再独立漂浮，而是直接放进项目执行面。"
        >
          <div className="space-y-3">
            {hub.experiments.map((experiment) => (
              <div
                key={experiment.id}
                className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5"
              >
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                  <div>
                    <div className="text-base font-semibold text-stone-900">
                      {experiment.title}
                    </div>
                    <div className="mt-2 text-sm text-stone-600">
                      状态 {experiment.status} · 时间 {formatDate(experiment.performedAt)}
                    </div>
                  </div>
                  <div className="text-sm text-stone-600">关联对象 {experiment.linkCount}</div>
                </div>
                <div className="mt-4 rounded-2xl bg-white px-4 py-3 text-sm leading-6 text-stone-700">
                  {experiment.latestReview
                    ? `${experiment.latestReview.reviewerName} · ${experiment.latestReview.status} · ${experiment.latestReview.comment ?? "暂无备注"}`
                    : "还没有审核记录。"}
                </div>
              </div>
            ))}
          </div>
        </SectionCard>

        <SectionCard
          title="流程与提醒"
          description="项目页先承接最需要持续盯住的执行信号。"
        >
          <div className="space-y-3">
            <div className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3 text-sm text-stone-700">
              待处理实验 {hub.pendingExperimentCount} 条
            </div>
            <div className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3 text-sm text-stone-700">
              低库存批次 {hub.lowStockLotCount} 个
            </div>
            <div className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3 text-sm text-stone-700">
              待执行预约 {hub.upcomingBookingCount} 条
            </div>
            {hub.workflows.map((workflow) => (
              <div
                key={workflow.id}
                className="rounded-2xl border border-stone-200 bg-white px-4 py-3"
              >
                <div className="text-sm font-semibold text-stone-900">
                  {workflow.workflowType}
                </div>
                <div className="mt-1 text-sm text-stone-600">状态 {workflow.status}</div>
                <div className="mt-2 text-xs leading-5 text-stone-500">
                  {workflow.latestTask
                    ? `${workflow.latestTask.stepKey} · ${workflow.latestTask.status} · ${workflow.latestTask.comment ?? "暂无备注"}`
                    : "暂无任务明细。"}
                </div>
              </div>
            ))}
          </div>
        </SectionCard>
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        <SectionCard
          title="样本"
          description="优先显示项目里真正被操作和追踪的实体。"
        >
          <div className="space-y-3">
            {hub.samples.map((sample) => (
              <div key={sample.id} className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3">
                <div className="text-sm font-semibold text-stone-900">
                  {sample.code} · {sample.name}
                </div>
                <div className="mt-1 text-sm text-stone-600">
                  {sample.sampleType} · {sample.status}
                </div>
                <div className="mt-2 text-xs leading-5 text-stone-500">
                  {sample.latestEvent
                    ? `${sample.latestEvent.eventType} · ${formatDate(sample.latestEvent.occurredAt)} · ${sample.latestEvent.note ?? "暂无说明"}`
                    : "暂无事件记录。"}
                </div>
              </div>
            ))}
          </div>
        </SectionCard>

        <SectionCard
          title="库存"
          description="先把会影响执行的试剂批次暴露出来。"
        >
          <div className="space-y-3">
            {hub.inventoryLots.map((lot) => (
              <div key={lot.id} className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3">
                <div className="text-sm font-semibold text-stone-900">
                  {lot.materialName}
                </div>
                <div className="mt-1 text-sm text-stone-600">
                  {lot.lotNo} · {lot.quantity ?? "未知"} {lot.unit ?? ""}
                </div>
                <div className="mt-2 text-xs leading-5 text-stone-500">
                  {lot.location ?? "位置待补充"}
                  {lot.latestTransaction
                    ? ` · ${lot.latestTransaction.type} · ${formatDate(lot.latestTransaction.occurredAt)}`
                    : " · 暂无流转记录"}
                </div>
              </div>
            ))}
          </div>
        </SectionCard>

        <SectionCard
          title="仪器与知识"
          description="设备和文档也应该被拉进项目上下文。"
        >
          <div className="space-y-3">
            {hub.instruments.map((instrument) => (
              <div
                key={instrument.id}
                className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3"
              >
                <div className="text-sm font-semibold text-stone-900">
                  {instrument.name}
                </div>
                <div className="mt-1 text-sm text-stone-600">
                  {instrument.code} · {instrument.status}
                </div>
                <div className="mt-2 text-xs leading-5 text-stone-500">
                  {instrument.location ?? "位置待补充"}
                  {instrument.latestBooking
                    ? ` · ${instrument.latestBooking.title} · ${formatDate(instrument.latestBooking.startAt)}`
                    : " · 暂无预约"}
                </div>
              </div>
            ))}
            {hub.documents.map((document) => (
              <div key={document.id} className="rounded-2xl border border-dashed border-stone-300 bg-white px-4 py-3">
                <div className="text-sm font-semibold text-stone-900">
                  {document.title}
                </div>
                <div className="mt-1 text-xs text-stone-500">
                  {document.docType} · 更新于 {formatDate(document.updatedAt)}
                </div>
              </div>
            ))}
          </div>
        </SectionCard>
      </div>

      <SectionCard
        title="近期事件流"
        description="先把多模块事件汇成一条时间线，后面再升级成真正的项目活动流。"
      >
        <div className="space-y-3">
          {hub.timeline.map((item) => (
            <div
              key={item.id}
              className="grid gap-2 rounded-[1.5rem] border border-stone-200 bg-stone-50/75 px-4 py-3 lg:grid-cols-[180px_1fr]"
            >
              <div className="text-sm font-medium text-stone-500">{formatDate(item.at)}</div>
              <div>
                <div className="text-sm font-semibold text-stone-900">{item.label}</div>
                <div className="mt-1 text-sm text-stone-600">{item.detail}</div>
              </div>
            </div>
          ))}
        </div>
      </SectionCard>
    </div>
  );
}
