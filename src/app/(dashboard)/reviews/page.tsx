import { ReviewBoard } from "@/features/reviews/components/review-board";
import { getReviewBoard } from "@/features/reviews/queries";
import { SectionCard } from "@/shared/ui/section-card";

export default async function ReviewsPage() {
  const reviewBoard = await getReviewBoard();

  return (
    <SectionCard
      title="导师审核中心"
      description="工作流层会先从实验审核开始，后续再扩展到样本申请、设备预约和归档审批。"
    >
      <ReviewBoard
        pending={reviewBoard.pending}
        returned={reviewBoard.returned}
        approved={reviewBoard.approved}
      />
    </SectionCard>
  );
}
