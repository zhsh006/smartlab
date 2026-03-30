"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { startTransition, useState } from "react";

type ReviewItem = {
  id: string;
  status: "SUBMITTED" | "NEEDS_REVISION" | "APPROVED";
  comment: string | null;
  createdAt: Date;
  reviewer: {
    name: string;
  };
  experiment: {
    id: string;
    title: string;
    status: string;
    project: {
      name: string;
    };
  };
};

type ReviewBoardProps = {
  pending: ReviewItem[];
  returned: ReviewItem[];
  approved: ReviewItem[];
};

function ReviewActionCard({ item }: { item: ReviewItem }) {
  const router = useRouter();
  const [comment, setComment] = useState("");
  const [statusMessage, setStatusMessage] = useState<string | null>(null);
  const [activeIntent, setActiveIntent] = useState<"APPROVE" | "REQUEST_REVISION" | null>(null);

  const submitAction = async (intent: "APPROVE" | "REQUEST_REVISION") => {
    if (intent === "REQUEST_REVISION" && !comment.trim()) {
      setStatusMessage("退回修改前需要填写原因");
      return;
    }

    setActiveIntent(intent);
    setStatusMessage(intent === "APPROVE" ? "正在批准..." : "正在退回修改...");

    try {
      const response = await fetch(`/api/reviews/${item.id}`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          intent,
          comment: comment.trim() || undefined,
        }),
      });

      const result = (await response.json()) as {
        ok: boolean;
        message?: string;
      };

      if (!response.ok || !result.ok) {
        throw new Error(result.message ?? "Review action failed");
      }

      setStatusMessage(result.message ?? "已完成");
      startTransition(() => {
        router.refresh();
      });
    } catch (error) {
      setStatusMessage(error instanceof Error ? error.message : "审核操作失败");
    } finally {
      setActiveIntent(null);
    }
  };

  return (
    <li className="rounded-2xl bg-white p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="font-medium text-stone-900">{item.experiment.title}</div>
          <div className="mt-1 text-sm text-stone-600">{item.experiment.project.name}</div>
        </div>
        <div className="rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold text-stone-700">
          {item.reviewer.name}
        </div>
      </div>

      <div className="mt-3 text-sm leading-6 text-stone-700">
        {item.comment ?? "等待审核意见"}
      </div>

      <div className="mt-3 flex flex-wrap gap-3 text-sm">
        <Link href={`/experiments/${item.experiment.id}`} className="text-stone-900 underline">
          打开实验详情
        </Link>
      </div>

      {item.status === "SUBMITTED" ? (
        <div className="mt-4 space-y-3">
          <textarea
            value={comment}
            onChange={(event) => setComment(event.target.value)}
            rows={3}
            placeholder="填写审核意见；退回修改时必填"
            className="w-full resize-none rounded-[1rem] border border-stone-200 bg-stone-50 px-3 py-3 text-sm leading-6 text-stone-700 outline-none placeholder:text-stone-400"
          />
          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={() => void submitAction("APPROVE")}
              disabled={activeIntent !== null}
              className="rounded-full bg-stone-900 px-4 py-2 text-sm font-medium text-stone-50 disabled:cursor-not-allowed disabled:bg-stone-300"
            >
              {activeIntent === "APPROVE" ? "处理中..." : "批准"}
            </button>
            <button
              type="button"
              onClick={() => void submitAction("REQUEST_REVISION")}
              disabled={activeIntent !== null}
              className="rounded-full border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 disabled:cursor-not-allowed disabled:border-stone-200 disabled:text-stone-400"
            >
              {activeIntent === "REQUEST_REVISION" ? "处理中..." : "退回修改"}
            </button>
          </div>
        </div>
      ) : null}

      {statusMessage ? <div className="mt-3 text-sm text-stone-600">{statusMessage}</div> : null}
    </li>
  );
}

export function ReviewBoard({ pending, returned, approved }: ReviewBoardProps) {
  const columns = [
    { title: "待处理", items: pending },
    { title: "已退回", items: returned },
    { title: "已通过", items: approved },
  ];

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      {columns.map((column) => (
        <div
          key={column.title}
          className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5"
        >
          <h3 className="font-semibold text-stone-900">{column.title}</h3>
          <ul className="mt-4 space-y-3 text-sm text-stone-700">
            {column.items.length > 0 ? (
              column.items.map((item) => <ReviewActionCard key={item.id} item={item} />)
            ) : (
              <li className="rounded-2xl bg-white px-4 py-3 text-stone-500">暂无记录</li>
            )}
          </ul>
        </div>
      ))}
    </div>
  );
}
