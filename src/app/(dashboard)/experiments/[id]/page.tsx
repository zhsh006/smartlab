import Link from "next/link";
import { notFound } from "next/navigation";
import { getQpcrSubmissionGate } from "@/features/experiments/assay-pack-qc";
import { getExperimentDetail } from "@/features/experiments/queries";
import { SectionCard } from "@/shared/ui/section-card";

type AssayPackRevisionPayload = {
  cover?: Record<string, string>;
  stepNotes?: string[];
  resultRows?: string[][];
  summaryNote?: string;
  intent?: "SAVE_DRAFT" | "SUBMIT_REVIEW";
  submittedAt?: string | null;
  reviewerName?: string | null;
};

function getAssayPackRevision(experiment: NonNullable<Awaited<ReturnType<typeof getExperimentDetail>>>) {
  const revision = experiment.revisions.find((item) =>
    item.action.startsWith("ASSAY_PACK_"),
  );

  if (!revision?.payload) {
    return null;
  }

  try {
    const payload = JSON.parse(revision.payload) as AssayPackRevisionPayload;
    const assayPackKey = revision.action
      .replace("ASSAY_PACK_", "")
      .replace("_DRAFT_SAVE", "")
      .replace("_SUBMITTED", "")
      .toLowerCase();

    return {
      assayPackKey,
      payload,
    };
  } catch {
    return null;
  }
}

export default async function ExperimentDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const experiment = await getExperimentDetail(id);

  if (!experiment) {
    notFound();
  }

  const sourceUrl = experiment.sourceRef
    ? `${process.env.ELABFTW_BASE_URL?.replace("/api/v2", "")}/experiments.php?mode=view&id=${experiment.sourceRef}`
    : null;
  const assayPackRevision = getAssayPackRevision(experiment);
  const assayPackCoverEntries = Object.entries(assayPackRevision?.payload.cover ?? {});
  const assayPackResultRows = assayPackRevision?.payload.resultRows ?? [];
  const assayPackStepNotes = assayPackRevision?.payload.stepNotes ?? [];
  const isQpcrAssayPack = assayPackRevision?.assayPackKey === "qpcr";
  const qPcrSubmissionGate =
    isQpcrAssayPack && assayPackRevision
      ? getQpcrSubmissionGate({
          cover: assayPackRevision.payload.cover ?? {},
          resultRows: assayPackRevision.payload.resultRows ?? [],
          projectId: experiment.projectId,
          sampleId: experiment.links.find((link) => link.entityType === "SAMPLE")?.entityId,
        })
      : null;
  const assayPackPhase = experiment.status === "DRAFT" ? "草稿视图" : "提交后视图";
  const reviewHeadline =
    experiment.reviews[0]?.status === "SUBMITTED"
      ? "等待导师审核"
      : experiment.reviews[0]?.status ?? experiment.status;
  const latestReviewComment = experiment.reviews[0]?.comment ?? null;

  return (
    <div className="grid gap-6">
      <SectionCard
        title={experiment.title}
        description="统一实验详情页会聚合实验正文摘要、实体引用、审核流转和分析结果。"
      >
        <div className="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
          <div className="space-y-4">
            <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5">
              <div className="text-sm text-stone-500">项目</div>
              <div className="mt-2 text-lg font-semibold text-stone-900">
                {experiment.project.name}
              </div>
              <p className="mt-4 text-sm leading-7 text-stone-700">
                {experiment.summary ?? "当前还没有同步到更完整的实验正文摘要。"}
              </p>
            </div>

            {assayPackRevision ? (
              <div className="rounded-[1.5rem] border border-stone-200 bg-white p-5">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <div className="text-sm text-stone-500">Assay Pack {assayPackPhase}</div>
                    <div className="mt-2 text-sm text-stone-700">
                      {experiment.status === "DRAFT"
                        ? "当前仍是草稿快照，结构化内容可继续编辑后再提交。"
                        : "当前快照来自提交审核时的最新模板内容，作为审核基线展示。"}
                    </div>
                  </div>
                  <div className="rounded-full bg-stone-900 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-stone-50 uppercase">
                    {assayPackRevision.assayPackKey}
                  </div>
                </div>

                <div className="mt-4 grid gap-4">
                  {experiment.status !== "DRAFT" ? (
                    <div className="rounded-[1.25rem] border border-[#d7e6ef] bg-[#eef5f9] p-4">
                      <div className="text-sm font-semibold text-stone-900">提交状态</div>
                      <div className="mt-3 grid gap-3 md:grid-cols-2">
                        <div className="rounded-2xl bg-white px-4 py-3 text-sm text-stone-700">
                          当前阶段：{reviewHeadline}
                        </div>
                        <div className="rounded-2xl bg-white px-4 py-3 text-sm text-stone-700">
                          审核人：{assayPackRevision.payload.reviewerName ?? "待分配"}
                        </div>
                        <div className="rounded-2xl bg-white px-4 py-3 text-sm text-stone-700">
                          提交时间：
                          {assayPackRevision.payload.submittedAt
                            ? new Intl.DateTimeFormat("zh-CN", {
                                dateStyle: "medium",
                                timeStyle: "short",
                              }).format(new Date(assayPackRevision.payload.submittedAt))
                            : "未记录"}
                        </div>
                        <div className="rounded-2xl bg-white px-4 py-3 text-sm text-stone-700">
                          QC：
                          {qPcrSubmissionGate?.canSubmit ? "已通过提交门禁" : "存在未通过项"}
                        </div>
                      </div>
                    </div>
                  ) : null}

                  {experiment.reviews[0]?.status === "NEEDS_REVISION" && latestReviewComment ? (
                    <div className="rounded-[1.25rem] border border-[#ead7cd] bg-[#fbf1eb] p-4">
                      <div className="text-sm font-semibold text-stone-900">退回原因</div>
                      <div className="mt-3 rounded-2xl bg-white px-4 py-3 text-sm leading-6 text-stone-700">
                        {latestReviewComment}
                      </div>
                    </div>
                  ) : null}

                  <div className="rounded-[1.25rem] border border-stone-200 bg-stone-50/75 p-4">
                    <div className="text-sm font-semibold text-stone-900">封面字段</div>
                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                      {assayPackCoverEntries.map(([field, value]) => (
                        <div
                          key={field}
                          className="rounded-2xl border border-white/80 bg-white px-4 py-3"
                        >
                          <div className="text-xs font-semibold tracking-[0.16em] text-stone-400 uppercase">
                            {field}
                          </div>
                          <div className="mt-2 text-sm text-stone-700">
                            {value || "未填写"}
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>

                  <div className="rounded-[1.25rem] border border-stone-200 bg-stone-50/75 p-4">
                    <div className="text-sm font-semibold text-stone-900">步骤备注</div>
                    <div className="mt-3 space-y-3">
                      {assayPackStepNotes.map((note, index) => (
                        <div
                          key={`${index + 1}`}
                          className="rounded-2xl border border-white/80 bg-white px-4 py-3 text-sm text-stone-700"
                        >
                          <div className="font-medium text-stone-900">Step {index + 1}</div>
                          <div className="mt-2 leading-6">
                            {note || "当前步骤还没有填写备注。"}
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>

                  <div className="rounded-[1.25rem] border border-stone-200 bg-stone-50/75 p-4">
                    <div className="text-sm font-semibold text-stone-900">结果表快照</div>
                    <div className="mt-3 space-y-3">
                      {assayPackResultRows.map((row, index) => (
                        <div
                          key={`${index + 1}`}
                          className="rounded-2xl border border-white/80 bg-white px-4 py-3 text-sm text-stone-700"
                        >
                          {row.join(" · ")}
                        </div>
                      ))}
                    </div>
                  </div>

                  <div className="rounded-[1.25rem] border border-stone-200 bg-stone-50/75 p-4">
                    <div className="text-sm font-semibold text-stone-900">实验摘要备注</div>
                    <div className="mt-3 rounded-2xl border border-white/80 bg-white px-4 py-3 text-sm leading-6 text-stone-700">
                      {assayPackRevision.payload.summaryNote || "当前还没有补充 Assay Pack 摘要备注。"}
                    </div>
                  </div>

                  {qPcrSubmissionGate ? (
                    <div className="rounded-[1.25rem] border border-stone-200 bg-stone-50/75 p-4">
                      <div className="text-sm font-semibold text-stone-900">提交门禁检查</div>
                      <ul className="mt-3 space-y-3">
                        {qPcrSubmissionGate.checks.map((item) => (
                          <li
                            key={item.key}
                            className="rounded-2xl border border-white/80 bg-white px-4 py-3 text-sm text-stone-700"
                          >
                            {item.passed ? "通过" : "阻断"} · {item.label}
                          </li>
                        ))}
                      </ul>
                    </div>
                  ) : null}
                </div>
              </div>
            ) : null}

            <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5">
              <div className="text-sm text-stone-500">关联对象</div>
              <ul className="mt-3 space-y-3">
                {experiment.links.map((link) => (
                  <li
                    key={link.id}
                    className="rounded-2xl bg-white px-4 py-3 text-sm text-stone-700"
                  >
                    {link.entityType} · {link.label ?? link.entityId}
                  </li>
                ))}
              </ul>
            </div>
          </div>

          <div className="space-y-4">
            <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5">
              <div className="text-sm text-stone-500">状态</div>
              <div className="mt-2 text-lg font-semibold text-stone-900">
                {reviewHeadline}
              </div>
              <div className="mt-4 space-y-2 text-sm text-stone-700">
                <div>作者：{experiment.authorName ?? "未知"}</div>
                <div>
                  执行时间：
                  {experiment.performedAt
                    ? new Intl.DateTimeFormat("zh-CN", {
                        dateStyle: "medium",
                        timeStyle: "short",
                      }).format(experiment.performedAt)
                    : "未记录"}
                </div>
                <div>来源：{experiment.sourceSystem ?? "LOCAL"}</div>
              </div>
              {sourceUrl ? (
                <Link
                  href={sourceUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="mt-4 inline-flex text-sm font-medium text-stone-900 underline"
                >
                  打开 eLabFTW 原始记录
                </Link>
              ) : null}
            </div>

            <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5">
              <div className="text-sm text-stone-500">审核记录</div>
              <ul className="mt-3 space-y-3">
                {experiment.reviews.map((review) => (
                  <li
                    key={review.id}
                    className="rounded-2xl bg-white px-4 py-3 text-sm text-stone-700"
                  >
                    <div className="font-medium text-stone-900">
                      {review.status} · {review.reviewer.name}
                    </div>
                    <div className="mt-1 leading-6">
                      {review.comment ?? "无补充意见"}
                    </div>
                  </li>
                ))}
              </ul>
            </div>

            <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5">
              <div className="text-sm text-stone-500">分析运行</div>
              <ul className="mt-3 space-y-3">
                {experiment.analysisRuns.map((run) => (
                  <li
                    key={run.id}
                    className="rounded-2xl bg-white px-4 py-3 text-sm text-stone-700"
                  >
                    <div className="font-medium text-stone-900">
                      {run.assayType} · {run.status}
                    </div>
                    <div className="mt-1 break-all leading-6">
                      {run.inputFileRef ?? "无输入文件"}
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      </SectionCard>
    </div>
  );
}
