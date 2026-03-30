"use client";

import { useState, useTransition } from "react";

type SyncState =
  | {
      ok: true;
      imported: number;
      skipped: number;
    }
  | {
      ok: false;
      message: string;
    }
  | null;

export function ElabftwSyncPanel({
  projectCode,
}: {
  projectCode: string;
}) {
  const [isPending, startTransition] = useTransition();
  const [result, setResult] = useState<SyncState>(null);

  function handleSync() {
    startTransition(async () => {
      const response = await fetch("/api/sync/elabftw", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ projectCode }),
      });

      const payload = (await response.json()) as SyncState;
      setResult(payload);
    });
  }

  return (
    <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/80 p-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h3 className="text-base font-semibold text-stone-900">
            eLabFTW 手动同步
          </h3>
          <p className="mt-1 text-sm leading-6 text-stone-600">
            使用当前项目代码 `{projectCode}` 将 eLabFTW 实验导入本地统一实验中心。
          </p>
        </div>
        <button
          type="button"
          onClick={handleSync}
          disabled={isPending}
          className="rounded-full bg-stone-900 px-4 py-2 text-sm font-medium text-stone-50 transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:bg-stone-400"
        >
          {isPending ? "同步中..." : "立即同步"}
        </button>
      </div>

      {result ? (
        <div
          className={`mt-4 rounded-2xl px-4 py-3 text-sm ${
            result.ok
              ? "bg-emerald-50 text-emerald-700"
              : "bg-amber-50 text-amber-700"
          }`}
        >
          {result.ok
            ? `已导入 ${result.imported} 条，跳过 ${result.skipped} 条。`
            : result.message}
        </div>
      ) : null}
    </div>
  );
}
