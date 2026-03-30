import { getSampleHub } from "@/features/samples/queries";
import { SectionCard } from "@/shared/ui/section-card";
import { StatCard } from "@/shared/ui/stat-card";

const sampleStatusLabel = {
  ACTIVE: "活跃",
  CONSUMED: "已消耗",
  ARCHIVED: "已归档",
  DESTROYED: "已销毁",
} as const;

const workflowStatusLabel = {
  PENDING: "待处理",
  IN_PROGRESS: "处理中",
  COMPLETED: "已完成",
  REJECTED: "已驳回",
  CANCELLED: "已取消",
} as const;

const approvalDecisionLabel = {
  APPROVED: "已批准",
  REJECTED: "已拒绝",
  RETURNED: "已退回",
} as const;

export default async function SamplesPage() {
  const hub = await getSampleHub();

  return (
    <div className="grid gap-6">
      <section className="grid gap-4 md:grid-cols-5">
        <StatCard
          label="样本实体"
          value={String(hub.summary.totalSamples)}
          hint="进入统一对象模型的样本总数"
        />
        <StatCard
          label="活跃样本"
          value={String(hub.summary.activeSamples)}
          hint="仍在实验流程中的实体"
        />
        <StatCard
          label="待处理流程"
          value={String(hub.summary.pendingWorkflowCount)}
          hint="样本申请、使用、销毁等流程任务"
        />
        <StatCard
          label="最近事件"
          value={String(hub.summary.recentEventCount)}
          hint="采集、分装、使用、销毁等轨迹"
        />
        <StatCard
          label="需补强对象"
          value={String(hub.summary.sampleRiskCount)}
          hint="未关联实验或无生命周期记录"
        />
      </section>

      <section className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
        <SectionCard
          title="样本与实体中心"
          description="这里不把样本当备注字段，而是把它作为可被实验、库存、流程和知识共同引用的一等对象。"
        >
          <div className="space-y-4 text-sm leading-7 text-stone-700">
            <p>
              样本中心是 LabSmart 超过普通 ELN 的第一步。对象一旦被建模，才能形成采集、分装、实验引用、审核、销毁和审计的连续链路。
            </p>
            <p>
              当前页面已经接入真实样本表、生命周期事件、关联实验和样本流程；下一步可以继续接样本详情页、批次视图和跨项目实体追踪。
            </p>
          </div>
        </SectionCard>

        <SectionCard
          title="对象治理原则"
          description="样本模块的目标不是列目录，而是让对象能进入后续系统动作。"
        >
          <ul className="space-y-3 text-sm text-stone-700">
            <li className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3">
              每个样本必须有唯一编码和明确类型，才能被实验与审批准确引用。
            </li>
            <li className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3">
              生命周期事件要持续追加，不能只在实验正文里零散描述。
            </li>
            <li className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3">
              使用、转移、销毁都应沉淀为流程和审计事件，而不是口头协作。
            </li>
          </ul>
        </SectionCard>
      </section>

      <section className="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
        <SectionCard
          title="样本主视图"
          description="按对象而不是按表单看实验室资产，才能让后续分析、审批和知识沉淀真正挂上去。"
        >
          <div className="grid gap-4">
            {hub.samples.map((sample) => (
              <article
                key={sample.id}
                className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5"
              >
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                  <div>
                    <div className="text-xs font-semibold tracking-[0.2em] text-stone-500 uppercase">
                      {sample.code}
                    </div>
                    <h3 className="mt-2 text-lg font-semibold text-stone-900">
                      {sample.name}
                    </h3>
                    <p className="mt-2 text-sm leading-6 text-stone-600">
                      {sample.project.name} · {sample.sampleType} · 状态 {sampleStatusLabel[sample.status]}
                    </p>
                  </div>

                  <div className="rounded-full bg-stone-900 px-3 py-1 text-xs font-semibold text-stone-50">
                    关联实验 {sample.relatedExperiments}
                  </div>
                </div>

                <div className="mt-4 grid gap-3 text-sm text-stone-700 md:grid-cols-2">
                  <div className="rounded-2xl bg-white px-4 py-3">
                    最新事件：
                    {sample.latestEvent
                      ? ` ${sample.latestEvent.eventType} · ${sample.latestEvent.note ?? "已记录"}`
                      : " 暂无生命周期事件"}
                  </div>
                  <div className="rounded-2xl bg-white px-4 py-3">
                    最近实验：
                    {sample.latestExperiment
                      ? ` ${sample.latestExperiment.title} · ${sample.latestExperiment.projectName}`
                      : " 尚未被实验引用"}
                  </div>
                </div>
              </article>
            ))}
          </div>
        </SectionCard>

        <SectionCard
          title="最近样本事件"
          description="用事件链而不是备注堆栈记录样本流转。"
        >
          <ul className="space-y-3 text-sm text-stone-700">
            {hub.recentEvents.map((event) => (
              <li
                key={event.id}
                className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3"
              >
                <div className="font-semibold text-stone-900">
                  {event.sampleCode} · {event.eventType}
                </div>
                <div className="mt-1">
                  {event.sampleName} · {event.projectName}
                </div>
                <div className="mt-1 text-stone-600">{event.occurredAtLabel}</div>
                {event.note ? <div className="mt-2 text-stone-600">{event.note}</div> : null}
              </li>
            ))}
          </ul>
        </SectionCard>
      </section>

      <section className="grid gap-6 lg:grid-cols-2">
        <SectionCard
          title="样本流程与审批"
          description="把样本对象带入流程层，系统才会真正管理实验室执行。"
        >
          <div className="space-y-3 text-sm text-stone-700">
            {hub.workflows.length > 0 ? (
              hub.workflows.map((workflow) => (
                <div
                  key={workflow.id}
                  className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3"
                >
                  <div className="flex items-center justify-between gap-3">
                    <div className="font-semibold text-stone-900">
                      {workflow.workflowType} · {workflow.projectName}
                    </div>
                    <div className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-stone-700">
                      {workflowStatusLabel[workflow.status]}
                    </div>
                  </div>
                  <div className="mt-2 text-stone-600">
                    实体 ID {workflow.entityId ?? "未绑定"}
                  </div>
                  <div className="mt-1 text-stone-600">
                    当前任务：
                    {workflow.latestTask
                      ? ` ${workflow.latestTask.stepKey} · ${workflow.latestTask.comment ?? workflowStatusLabel[workflow.latestTask.status]}`
                      : " 暂无任务"}
                  </div>
                </div>
              ))
            ) : (
              <div className="rounded-2xl border border-dashed border-stone-300 bg-stone-50/70 px-4 py-3 text-sm text-stone-500">
                暂无样本流程记录
              </div>
            )}
          </div>
        </SectionCard>

        <SectionCard
          title="最近审批动作"
          description="审批不是单独模块，而是样本对象生命周期的一部分。"
        >
          <div className="space-y-3 text-sm text-stone-700">
            {hub.approvals.length > 0 ? (
              hub.approvals.map((approval) => (
                <div
                  key={approval.id}
                  className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-3"
                >
                  <div className="flex items-center justify-between gap-3">
                    <div className="font-semibold text-stone-900">
                      样本审批 · {approval.approverName}
                    </div>
                    <div className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-stone-700">
                      {approvalDecisionLabel[approval.decision]}
                    </div>
                  </div>
                  <div className="mt-2 text-stone-600">实体 ID {approval.entityId}</div>
                  <div className="mt-1 text-stone-600">{approval.createdAtLabel}</div>
                  {approval.comment ? (
                    <div className="mt-2 text-stone-600">{approval.comment}</div>
                  ) : null}
                </div>
              ))
            ) : (
              <div className="rounded-2xl border border-dashed border-stone-300 bg-stone-50/70 px-4 py-3 text-sm text-stone-500">
                暂无审批动作
              </div>
            )}
          </div>
        </SectionCard>
      </section>
    </div>
  );
}
