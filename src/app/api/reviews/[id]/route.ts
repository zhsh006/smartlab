import { revalidatePath } from "next/cache";
import { NextResponse } from "next/server";
import { z } from "zod";
import { db } from "@/server/db/client";

const reviewActionSchema = z.object({
  intent: z.enum(["APPROVE", "REQUEST_REVISION"]),
  comment: z.string().optional(),
});

export async function POST(
  request: Request,
  context: RouteContext<"/api/reviews/[id]">,
) {
  try {
    const { id } = await context.params;
    const body = await request.json();
    const action = reviewActionSchema.parse(body);
    const normalizedComment = action.comment?.trim() ?? "";

    if (action.intent === "REQUEST_REVISION" && !normalizedComment) {
      return NextResponse.json(
        { ok: false, message: "退回修改必须填写原因" },
        { status: 400 },
      );
    }

    const review = await db.$transaction(async (tx) => {
      const existingReview = await tx.experimentReview.findUnique({
        where: { id },
        include: {
          experiment: true,
        },
      });

      if (!existingReview) {
        throw new Error("审核记录不存在");
      }

      if (existingReview.status !== "SUBMITTED") {
        throw new Error("当前审核记录不在待处理状态");
      }

      const workflowRun = await tx.workflowRun.findFirst({
        where: {
          entityId: existingReview.experimentId,
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
      });

      const isApproved = action.intent === "APPROVE";
      const reviewStatus = isApproved ? "APPROVED" : "NEEDS_REVISION";
      const experimentStatus = isApproved ? "APPROVED" : "NEEDS_REVISION";
      const workflowStatus = isApproved ? "COMPLETED" : "REJECTED";
      const reviewComment = normalizedComment || "审核通过";

      const updatedReview = await tx.experimentReview.update({
        where: { id: existingReview.id },
        data: {
          status: reviewStatus,
          comment: reviewComment,
        },
      });

      await tx.experiment.update({
        where: { id: existingReview.experimentId },
        data: {
          status: experimentStatus,
        },
      });

      await tx.experimentRevision.create({
        data: {
          experimentId: existingReview.experimentId,
          action: isApproved ? "REVIEW_APPROVED" : "REVIEW_NEEDS_REVISION",
          payload: JSON.stringify({
            reviewId: existingReview.id,
            comment: reviewComment,
          }),
        },
      });

      if (workflowRun) {
        await tx.workflowRun.update({
          where: { id: workflowRun.id },
          data: {
            status: workflowStatus,
          },
        });

        const latestTask = workflowRun.tasks[0];
        if (latestTask) {
          await tx.workflowTask.update({
            where: { id: latestTask.id },
            data: {
              status: workflowStatus,
              comment: reviewComment,
            },
          });
        }
      }

      return {
        id: updatedReview.id,
        experimentId: existingReview.experimentId,
      };
    });

    revalidatePath("/reviews");
    revalidatePath("/experiments");
    revalidatePath(`/experiments/${review.experimentId}`);

    return NextResponse.json({
      ok: true,
      reviewId: review.id,
      message: action.intent === "APPROVE" ? "审核已通过" : "已退回修改",
    });
  } catch (error) {
    if (error instanceof z.ZodError) {
      return NextResponse.json(
        { ok: false, message: "Invalid review action payload", issues: error.flatten() },
        { status: 400 },
      );
    }

    return NextResponse.json(
      {
        ok: false,
        message: error instanceof Error ? error.message : "Unknown review action error",
      },
      { status: 500 },
    );
  }
}
