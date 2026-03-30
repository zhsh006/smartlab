import { beforeEach, describe, expect, it, vi } from "vitest";

const revalidatePath = vi.fn();

const db = {
  $transaction: vi.fn(),
};

vi.mock("next/cache", () => ({
  revalidatePath,
}));

vi.mock("@/server/db/client", () => ({
  db,
}));

describe("/api/assay-packs/draft POST", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("submits a draft into review and creates review workflow artifacts", async () => {
    const tx = {
      experiment: {
        findUnique: vi.fn().mockResolvedValue({
          id: "exp-1",
          status: "DRAFT",
        }),
        update: vi.fn().mockResolvedValue({
          id: "exp-1",
        }),
      },
      projectMember: {
        findFirst: vi.fn().mockResolvedValue({
          userId: "mentor-1",
          user: { name: "刘导师" },
        }),
      },
      experimentReview: {
        findFirst: vi.fn().mockResolvedValue(null),
        create: vi.fn().mockResolvedValue({ id: "review-1" }),
        update: vi.fn(),
      },
      workflowRun: {
        findFirst: vi.fn().mockResolvedValue(null),
        create: vi.fn().mockResolvedValue({ id: "workflow-1" }),
        update: vi.fn(),
      },
      workflowTask: {
        create: vi.fn(),
        update: vi.fn(),
      },
      experimentLink: {
        deleteMany: vi.fn(),
        createMany: vi.fn(),
      },
      experimentRevision: {
        create: vi.fn(),
      },
    };

    db.$transaction.mockImplementation(async (callback) => callback(tx));

    const { POST } = await import("@/app/api/assay-packs/draft/route");
    const response = await POST(
      new Request("http://localhost/api/assay-packs/draft", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          intent: "SUBMIT_REVIEW",
          experimentId: "cm1234567890123456789012",
          assayPackKey: "qpcr",
          title: "qPCR · P-01",
          summary: "summary",
          performedAt: "2026-03-30",
          projectId: "cmproject123456789012345",
          sampleId: "cmsample1234567890123456",
          payload: {
            cover: {
              实验目的: "验证表达变化",
              课题编号: "P-01",
              操作者: "林同学",
              实验日期: "2026-03-30",
            },
            stepNotes: ["note"],
            resultRows: [
              ["Sample-01", "GAPDH", "22.41", "18.72", "3.69", "0.00", "1.00"],
              ["Sample-02", "GAPDH", "22.08", "18.27", "3.81", "1.12", "2.17"],
              ["NTC", "GAPDH", "Undetermined", "-", "-", "-", "-"],
            ],
            summaryNote: "summary",
          },
        }),
      }),
    );

    expect(response.ok).toBe(true);
    expect(tx.experiment.update).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          status: "SUBMITTED",
        }),
      }),
    );
    expect(tx.experimentReview.create).toHaveBeenCalled();
    expect(tx.workflowRun.create).toHaveBeenCalled();
    expect(tx.experimentRevision.create).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          action: "ASSAY_PACK_QPCR_SUBMITTED",
        }),
      }),
    );
    expect(revalidatePath).toHaveBeenCalledWith("/reviews");
  });

  it("reuses returned review records when submitting again after revision", async () => {
    const tx = {
      experiment: {
        findUnique: vi.fn().mockResolvedValue({
          id: "exp-1",
          status: "NEEDS_REVISION",
        }),
        update: vi.fn().mockResolvedValue({
          id: "exp-1",
        }),
      },
      projectMember: {
        findFirst: vi.fn().mockResolvedValue({
          userId: "mentor-1",
          user: { name: "刘导师" },
        }),
      },
      experimentReview: {
        findFirst: vi.fn().mockResolvedValue({
          id: "review-1",
          status: "NEEDS_REVISION",
        }),
        create: vi.fn(),
        update: vi.fn().mockResolvedValue({ id: "review-1" }),
      },
      workflowRun: {
        findFirst: vi.fn().mockResolvedValue({
          id: "workflow-1",
          tasks: [{ id: "task-1" }],
        }),
        create: vi.fn(),
        update: vi.fn(),
      },
      workflowTask: {
        create: vi.fn(),
        update: vi.fn(),
      },
      experimentLink: {
        deleteMany: vi.fn(),
        createMany: vi.fn(),
      },
      experimentRevision: {
        create: vi.fn(),
      },
    };

    db.$transaction.mockImplementation(async (callback) => callback(tx));

    const { POST } = await import("@/app/api/assay-packs/draft/route");
    const response = await POST(
      new Request("http://localhost/api/assay-packs/draft", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          intent: "SUBMIT_REVIEW",
          experimentId: "cm1234567890123456789012",
          assayPackKey: "qpcr",
          title: "qPCR · P-01",
          summary: "summary",
          performedAt: "2026-03-30",
          projectId: "cmproject123456789012345",
          sampleId: "cmsample1234567890123456",
          payload: {
            cover: {
              实验目的: "验证表达变化",
              课题编号: "P-01",
              操作者: "林同学",
              实验日期: "2026-03-30",
            },
            stepNotes: ["note"],
            resultRows: [
              ["Sample-01", "GAPDH", "22.41", "18.72", "3.69", "0.00", "1.00"],
              ["Sample-02", "GAPDH", "22.08", "18.27", "3.81", "1.12", "2.17"],
              ["NTC", "GAPDH", "Undetermined", "-", "-", "-", "-"],
            ],
            summaryNote: "summary",
          },
        }),
      }),
    );

    expect(response.ok).toBe(true);
    expect(tx.experimentReview.update).toHaveBeenCalledWith(
      expect.objectContaining({
        where: { id: "review-1" },
        data: expect.objectContaining({
          status: "SUBMITTED",
        }),
      }),
    );
    expect(tx.experimentReview.create).not.toHaveBeenCalled();
    expect(tx.workflowRun.update).toHaveBeenCalledWith(
      expect.objectContaining({
        where: { id: "workflow-1" },
        data: { status: "IN_PROGRESS" },
      }),
    );
    expect(tx.workflowTask.update).toHaveBeenCalledWith(
      expect.objectContaining({
        where: { id: "task-1" },
        data: expect.objectContaining({
          status: "PENDING",
        }),
      }),
    );
  });
});
