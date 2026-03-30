"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { primaryNavigation } from "@/features/portal/config";
import { cn } from "@/shared/lib/utils";

export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();

  return (
    <div className="min-h-screen bg-[linear-gradient(180deg,_#f5f1e8_0%,_#efe6d2_100%)] text-stone-900">
      <div className="mx-auto grid min-h-screen max-w-7xl gap-6 px-4 py-5 lg:grid-cols-[280px_1fr] lg:px-6">
        <aside className="rounded-[2rem] border border-stone-300/80 bg-white/75 p-5 shadow-[0_18px_50px_-36px_rgba(63,52,36,0.45)] backdrop-blur">
          <div className="border-b border-stone-200 pb-5">
            <p className="text-xs font-semibold tracking-[0.25em] text-stone-500 uppercase">
              Tingan LabSmart
            </p>
            <h1 className="mt-3 text-2xl font-semibold text-stone-900">
              科研操作系统
            </h1>
            <p className="mt-2 text-sm leading-6 text-stone-600">
              把实验、实体、库存、仪器、流程、分析、知识和 AI 放进同一套科研工作台。
            </p>
          </div>

          <nav className="mt-5 space-y-2">
            {primaryNavigation.map((item) => {
              const isActive =
                pathname === item.href || pathname.startsWith(`${item.href}/`);

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={cn(
                    "block rounded-2xl border px-4 py-3 transition",
                    isActive
                      ? "border-stone-900 bg-stone-900 text-stone-50"
                      : "border-transparent bg-stone-100/70 text-stone-800 hover:border-stone-300 hover:bg-white",
                  )}
                >
                  <div className="text-sm font-semibold">{item.label}</div>
                  <div
                    className={cn(
                      "mt-1 text-xs leading-5",
                      isActive ? "text-stone-300" : "text-stone-500",
                    )}
                  >
                    {item.description}
                  </div>
                </Link>
              );
            })}
          </nav>
        </aside>

        <div className="flex flex-col gap-6">
          <header className="rounded-[2rem] border border-stone-300/80 bg-[linear-gradient(135deg,_#173f5f_0%,_#214a6a_100%)] px-6 py-5 text-stone-50 shadow-[0_18px_50px_-36px_rgba(23,63,95,0.8)]">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <p className="text-xs font-semibold tracking-[0.25em] text-stone-300 uppercase">
                  LabSmart OS
                </p>
                <h2 className="mt-2 text-2xl font-semibold">
                  为科研团队统一管理实验执行、对象流转与知识沉淀
                </h2>
              </div>
              <p className="max-w-xl text-sm leading-6 text-stone-200">
                当前阶段先把产品骨架、统一模型和合规底座建立清楚，再逐步接入真实
                eLabFTW / InvenTree 与后续分析能力。
              </p>
            </div>
          </header>

          <main>{children}</main>
        </div>
      </div>
    </div>
  );
}
