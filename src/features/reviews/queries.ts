import { db } from "@/server/db/client";

export async function getReviewBoard() {
  const reviews = await db.experimentReview.findMany({
    orderBy: { createdAt: "desc" },
    include: {
      experiment: {
        include: {
          project: true,
        },
      },
      reviewer: true,
    },
  });

  return {
    pending: reviews.filter((item) => item.status === "SUBMITTED"),
    returned: reviews.filter((item) => item.status === "NEEDS_REVISION"),
    approved: reviews.filter((item) => item.status === "APPROVED"),
  };
}
