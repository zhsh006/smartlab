import { revalidatePath } from "next/cache";
import { NextResponse } from "next/server";
import { z } from "zod";
import { getQpcrSubmissionGate } from "@/features/experiments/assay-pack-qc";
import { db } from "@/server/db/client";

const draftSchema = z.object({
  intent: z.enum(["SAVE_DRAFT", "SUBMIT_REVIEW"]).default("SAVE_DRAFT"),
  experimentId: z.string().cuid().optional(),
  assayPackKey: z.string().min(1),
  title: z.string().min(1),
  summary: z.string().optional(),
  performedAt: z.string().optional(),
  projectId: z.string().cuid(),
  sampleId: z.string().cuid().optional(),
  lotId: z.string().cuid().optional(),
  instrumentId: z.string().cuid().optional(),
  payload: z.object({
    cover: z.record(z.string(), z.string()),
    stepNotes: z.array(z.string()),
    resultRows: z.array(z.array(z.string())),
    summaryNote: z.string(),
  }),
});

function parsePerformedAt(value?: string) {
  if (!value) {
    return null;
  }

  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export async function POST(request: Request) {
  try {
    const body = await request.json();
    const draft = draftSchema.parse(body);
    const isSubmitting = draft.intent === "SUBMIT_REVIEW";

    if (draft.assayPackKey === "qpcr" && isSubmitting) {
      const submissionGate = getQpcrSubmissionGate({
        cover: draft.payload.cover,
        resultRows: draft.payload.resultRows,
        projectId: draft.projectId,
        sampleId: draft.sampleId,
      });

      if (!submissionGate.canSubmit) {
        return NextResponse.json(
          {
            ok: false,
            message: "qPCR 质控未通过，暂不能提交审核",
            checks: submissionGate.checks,
          },
          { status: 400 },
        );
      }
    }

    const experiment = await db.$transaction(async (tx) => {
      const existingRecord = draft.experimentId
        ? await tx.experiment.findUnique({
            where: { id: draft.experimentId },
            select: {
              id: true,
              status: true,
            },
          })
        : null;

      if (existingRecord && !isSubmitting && existingRecord.status !== "DRAFT") {
        throw new Error("实验已进入审核流程，不能再覆盖为草稿");
      }

      if (
        existingRecord &&
        isSubmitting &&
        !["DRAFT", "NEEDS_REVISION"].includes(existingRecord.status)
      ) {
        throw new Error("当前实验状态不允许重复提交审核");
      }

      const reviewerMembership = isSubmitting
        ? await tx.projectMember.findFirst({
            where: {
              projectId: draft.projectId,
              user: {
                isActive: true,
                role: {
                  in: ["MENTOR", "PI"],
                },
              },
            },
            include: {
              user: true,
            },
            orderBy: {
              joinedAt: "asc",
            },
          })
        : null;

      if (isSubmitting && !reviewerMembership) {
        throw new Error("当前项目没有可分配的导师审核人");
      }

      const existingReview = existingRecord
        ? await tx.experimentReview.findFirst({
            where: {
              experimentId: existingRecord.id,
            },
            orderBy: {
              createdAt: "desc",
            },
          })
        : null;

      const existingWorkflowRun = existingRecord
        ? await tx.workflowRun.findFirst({
            where: {
              entityId: existingRecord.id,
              workflowType: "EXPERIMENT_REVIEW",
            },
            include: {
              tasks: {
                orderBy: {
                  updatedAt: "desc",
                },
                take: 1,
              },
            },
            orderBy: {
              createdAt: "desc",
            },
          })
        : null;

      const record = draft.experimentId
        ? await tx.experiment.update({
            where: { id: draft.experimentId },
            data: {
              projectId: draft.projectId,
              title: draft.title,
              summary: draft.summary,
              performedAt: parsePerformedAt(draft.performedAt),
              status: isSubmitting ? "SUBMITTED" : "DRAFT",
              authorName: "LabSmart Assay Pack",
            },
          })
        : await tx.experiment.create({
            data: {
              projectId: draft.projectId,
              title: draft.title,
              summary: draft.summary,
              performedAt: parsePerformedAt(draft.performedAt),
              status: isSubmitting ? "SUBMITTED" : "DRAFT",
              authorName: "LabSmart Assay Pack",
            },
          });

      await tx.experimentLink.deleteMany({
        where: { experimentId: record.id },
      });

      const links: Array<{
        experimentId: string;
        entityType: "SAMPLE" | "MATERIAL_LOT" | "INSTRUMENT";
        entityId: string;
        label: string;
      }> = [];

      if (draft.sampleId) {
        links.push({
          experimentId: record.id,
          entityType: "SAMPLE",
          entityId: draft.sampleId,
          label: "Assay Pack 样本",
        });
      }

      if (draft.lotId) {
        links.push({
          experimentId: record.id,
          entityType: "MATERIAL_LOT",
          entityId: draft.lotId,
          label: "Assay Pack 试剂批次",
        });
      }

      if (draft.instrumentId) {
        links.push({
          experimentId: record.id,
          entityType: "INSTRUMENT",
          entityId: draft.instrumentId,
          label: "Assay Pack 仪器",
        });
      }

      if (links.length > 0) {
        await tx.experimentLink.createMany({
          data: links,
        });
      }

      await tx.experimentRevision.create({
        data: {
          experimentId: record.id,
          action: `ASSAY_PACK_${draft.assayPackKey.toUpperCase()}_${
            isSubmitting ? "SUBMITTED" : "DRAFT_SAVE"
          }`,
          payload: JSON.stringify({
            ...draft.payload,
            intent: draft.intent,
            submittedAt: isSubmitting ? new Date().toISOString() : null,
            reviewerName: reviewerMembership?.user.name ?? null,
          }),
        },
      });

      if (isSubmitting) {
        if (existingReview?.status === "NEEDS_REVISION") {
          await tx.experimentReview.update({
            where: { id: existingReview.id },
            data: {
              status: "SUBMITTED",
              comment: "已根据退回意见重新提交审核。",
            },
          });
        } else {
          await tx.experimentReview.create({
            data: {
              experimentId: record.id,
              reviewerId: reviewerMembership!.userId,
              status: "SUBMITTED",
              comment: "等待导师审核 qPCR Assay Pack 提交。",
            },
          });
        }

        if (existingWorkflowRun) {
          await tx.workflowRun.update({
            where: { id: existingWorkflowRun.id },
            data: {
              status: "IN_PROGRESS",
            },
          });

          const latestTask = existingWorkflowRun.tasks[0];
          if (latestTask) {
            await tx.workflowTask.update({
              where: { id: latestTask.id },
              data: {
                assigneeId: reviewerMembership!.userId,
                status: "PENDING",
                comment: `${draft.assayPackKey.toUpperCase()} 已重新提交，等待导师审核。`,
              },
            });
          } else {
            await tx.workflowTask.create({
              data: {
                workflowRunId: existingWorkflowRun.id,
                stepKey: "mentor-review",
                assigneeId: reviewerMembership!.userId,
                status: "PENDING",
                comment: `${draft.assayPackKey.toUpperCase()} 已提交，等待导师审核。`,
              },
            });
          }
        } else {
          await tx.workflowRun.create({
            data: {
              projectId: draft.projectId,
              workflowType: "EXPERIMENT_REVIEW",
              entityId: record.id,
              status: "IN_PROGRESS",
              tasks: {
                create: {
                  stepKey: "mentor-review",
                  assigneeId: reviewerMembership!.userId,
                  status: "PENDING",
                  comment: `${draft.assayPackKey.toUpperCase()} 已提交，等待导师审核。`,
                },
              },
            },
          });
        }
      }

      return record;
    });

    revalidatePath(`/experiments/${experiment.id}`);
    revalidatePath("/reviews");

    return NextResponse.json({
      ok: true,
      experimentId: experiment.id,
      message: isSubmitting ? "Assay Pack 已提交审核" : "Assay Pack 草稿已保存",
    });
  } catch (error) {
    if (error instanceof z.ZodError) {
      return NextResponse.json(
        { ok: false, message: "Invalid draft payload", issues: error.flatten() },
        { status: 400 },
      );
    }

    return NextResponse.json(
      {
        ok: false,
        message: error instanceof Error ? error.message : "Unknown draft save error",
      },
      { status: 500 },
    );
  }
}
