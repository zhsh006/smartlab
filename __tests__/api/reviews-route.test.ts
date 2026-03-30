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

describe("/api/reviews/[id] POST", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("approves a submitted review and completes the workflow", async () => {
    const tx = {
      experimentReview: {
        findUnique: vi.fn().mockResolvedValue({
          id: "review-1",
          experimentId: "exp-1",
          status: "SUBMITTED",
          experiment: { id: "exp-1" },
        }),
        update: vi.fn().mockResolvedValue({ id: "review-1" }),
      },
      workflowRun: {
        findFirst: vi.fn().mockResolvedValue({
          id: "workflow-1",
          tasks: [{ id: "task-1" }],
        }),
        update: vi.fn(),
      },
      workflowTask: {
        update: vi.fn(),
      },
      experiment: {
        update: vi.fn(),
      },
      experimentRevision: {
        create: vi.fn(),
      },
    };

    db.$transaction.mockImplementation(async (callback) => callback(tx));

    const { POST } = await import("@/app/api/reviews/[id]/route");
    const response = await POST(
      new Request("http://localhost/api/reviews/review-1", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          intent: "APPROVE",
        }),
      }),
      {
        params: Promise.resolve({ id: "review-1" }),
      },
    );

    expect(response.ok).toBe(true);
    expect(tx.experimentReview.update).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          status: "APPROVED",
        }),
      }),
    );
    expect(tx.experiment.update).toHaveBeenCalledWith(
      expect.objectContaining({
        data: { status: "APPROVED" },
      }),
    );
    expect(tx.workflowRun.update).toHaveBeenCalledWith(
      expect.objectContaining({
        data: { status: "COMPLETED" },
      }),
    );
    expect(tx.workflowTask.update).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          status: "COMPLETED",
        }),
      }),
    );
  });

  it("returns a submitted review for revision with a required comment", async () => {
    const tx = {
      experimentReview: {
        findUnique: vi.fn().mockResolvedValue({
          id: "review-1",
          experimentId: "exp-1",
          status: "SUBMITTED",
          experiment: { id: "exp-1" },
        }),
        update: vi.fn().mockResolvedValue({ id: "review-1" }),
      },
      workflowRun: {
        findFirst: vi.fn().mockResolvedValue({
          id: "workflow-1",
          tasks: [{ id: "task-1" }],
        }),
        update: vi.fn(),
      },
      workflowTask: {
        update: vi.fn(),
      },
      experiment: {
        update: vi.fn(),
      },
      experimentRevision: {
        create: vi.fn(),
      },
    };

    db.$transaction.mockImplementation(async (callback) => callback(tx));

    const { POST } = await import("@/app/api/reviews/[id]/route");
    const response = await POST(
      new Request("http://localhost/api/reviews/review-1", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          intent: "REQUEST_REVISION",
          comment: "请补充异常孔解释。",
        }),
      }),
      {
        params: Promise.resolve({ id: "review-1" }),
      },
    );

    expect(response.ok).toBe(true);
    expect(tx.experimentReview.update).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          status: "NEEDS_REVISION",
          comment: "请补充异常孔解释。",
        }),
      }),
    );
    expect(tx.experiment.update).toHaveBeenCalledWith(
      expect.objectContaining({
        data: { status: "NEEDS_REVISION" },
      }),
    );
    expect(tx.workflowRun.update).toHaveBeenCalledWith(
      expect.objectContaining({
        data: { status: "REJECTED" },
      }),
    );
    expect(tx.workflowTask.update).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          status: "REJECTED",
          comment: "请补充异常孔解释。",
        }),
      }),
    );
  });
});
