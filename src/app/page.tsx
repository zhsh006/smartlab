import Link from "next/link";
import {
  complianceFoundation,
  coreModules,
  operatingPillars,
} from "@/features/portal/blueprint";

const quickLinks = [
  {
    href: "/workspace",
    title: "进入 LabSmart 工作台",
    description: "从一个首页调度实验、样本、库存、流程、分析和知识。",
  },
  {
    href: "/projects",
    title: "查看项目中枢",
    description: "把实验、样本、库存、仪器和审批统一放入课题上下文。",
  },
  {
    href: "/analysis",
    title: "进入数据分析中心",
    description: "把分析运行、结果摘要和阶段结论从零散脚本中拉回系统内。",
  },
];

export default function Home() {
  return (
    <main className="flex min-h-screen flex-col bg-[radial-gradient(circle_at_top_left,_rgba(23,63,95,0.15),_transparent_34%),linear-gradient(180deg,_#f5f1e8_0%,_#efe6d2_100%)]">
      <section className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-12 px-6 py-12 lg:px-10">
        <div className="flex flex-col gap-6 border-b border-stone-300/70 pb-10">
          <span className="w-fit rounded-full border border-stone-400/60 bg-white/60 px-3 py-1 text-xs font-semibold tracking-[0.2em] text-stone-700 uppercase">
            Tingan LabSmart
          </span>
          <div className="max-w-4xl space-y-4">
            <h1 className="font-serif text-4xl leading-tight font-semibold text-stone-900 sm:text-5xl">
              不再做低配 ELN，而是做科研团队真正会每天使用的科研操作系统
            </h1>
            <p className="max-w-3xl text-base leading-8 text-stone-700 sm:text-lg">
              Tingan LabSmart 融合 ELN、LIMS、项目协作、数据分析和 AI
              辅助能力，在一个平台内完成实验记录、样本与库存管理、导师审核、数据解析、知识沉淀与阶段汇报。
            </p>
          </div>
        </div>

        <div className="grid gap-5 lg:grid-cols-[1.5fr_1fr]">
          <div className="rounded-[2rem] border border-stone-300/80 bg-white/75 p-6 shadow-[0_24px_70px_-42px_rgba(63,52,36,0.45)] backdrop-blur">
            <div className="grid gap-5 md:grid-cols-3">
              {quickLinks.map((item) => (
                <Link
                  key={item.href}
                  href={item.href}
                  className="group rounded-[1.5rem] border border-stone-200 bg-stone-50/80 p-5 transition hover:-translate-y-0.5 hover:border-stone-400 hover:bg-white"
                >
                  <h2 className="text-lg font-semibold text-stone-900">
                    {item.title}
                  </h2>
                  <p className="mt-3 text-sm leading-6 text-stone-600">
                    {item.description}
                  </p>
                  <span className="mt-6 inline-flex text-sm font-medium text-stone-900">
                    打开模块
                  </span>
                </Link>
              ))}
            </div>
          </div>

          <aside className="rounded-[2rem] border border-stone-300/80 bg-[#173f5f] p-6 text-stone-50 shadow-[0_24px_70px_-42px_rgba(23,63,95,0.8)]">
            <h2 className="text-xl font-semibold">系统定义</h2>
            <ul className="mt-5 space-y-3 text-sm leading-6 text-stone-200">
              <li>底层像 Benchling：统一对象和流程数据</li>
              <li>中层像 Labguru：围绕课题组日常工作台组织</li>
              <li>前台像 eLabFTW + Notion：记录自由但合规可追溯</li>
              <li>AI 建立在结构化科研资产之上，而不是外挂问答</li>
            </ul>
          </aside>
        </div>

        <section className="grid gap-5 lg:grid-cols-4">
          {operatingPillars.map((pillar) => (
            <article
              key={pillar.title}
              className="rounded-[1.75rem] border border-stone-300/80 bg-white/70 p-5 shadow-[0_18px_50px_-40px_rgba(63,52,36,0.35)]"
            >
              <h2 className="text-lg font-semibold text-stone-900">{pillar.title}</h2>
              <p className="mt-3 text-sm leading-6 text-stone-600">
                {pillar.description}
              </p>
            </article>
          ))}
        </section>

        <section className="space-y-5">
          <div className="max-w-3xl space-y-3">
            <h2 className="text-2xl font-semibold text-stone-900">8 个核心模块</h2>
            <p className="text-sm leading-7 text-stone-600 sm:text-base">
              这套系统不是复制某一个产品，而是围绕科研团队的完整执行链路来组织模块。每个模块都必须既可独立使用，又能进入统一数据模型。
            </p>
          </div>
          <div className="grid gap-5 lg:grid-cols-2">
            {coreModules.map((module) => (
              <Link
                key={module.href}
                href={module.href}
                className="rounded-[1.75rem] border border-stone-300/80 bg-white/75 p-6 shadow-[0_18px_50px_-40px_rgba(63,52,36,0.35)] transition hover:-translate-y-0.5 hover:border-stone-500 hover:bg-white"
              >
                <div className="text-xs font-semibold tracking-[0.24em] text-stone-500 uppercase">
                  {module.eyebrow}
                </div>
                <h3 className="mt-3 text-xl font-semibold text-stone-900">
                  {module.title}
                </h3>
                <p className="mt-3 text-sm leading-6 text-stone-600">
                  {module.description}
                </p>
                <p className="mt-4 text-sm font-medium leading-6 text-stone-800">
                  {module.outcome}
                </p>
              </Link>
            ))}
          </div>
        </section>

        <section className="grid gap-5 lg:grid-cols-[1.3fr_0.7fr]">
          <div className="rounded-[1.75rem] border border-stone-300/80 bg-white/75 p-6 shadow-[0_18px_50px_-40px_rgba(63,52,36,0.35)]">
            <h2 className="text-2xl font-semibold text-stone-900">为什么不是普通实验记录系统</h2>
            <p className="mt-4 max-w-3xl text-sm leading-7 text-stone-600 sm:text-base">
              普通 ELN 的边界通常停在“写实验记录”。LabSmart 的边界要延展到实体对象、库存消耗、预约使用、审核流转、分析结果、知识复用与 AI 辅助，因此它更接近实验室的操作系统，而不是一个文档工具。
            </p>
          </div>
          <div className="rounded-[1.75rem] border border-stone-300/80 bg-stone-900 p-6 text-stone-50 shadow-[0_18px_50px_-40px_rgba(28,25,23,0.5)]">
            <h2 className="text-lg font-semibold">合规底座</h2>
            <ul className="mt-4 space-y-3 text-sm leading-6 text-stone-300">
              {complianceFoundation.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </div>
        </section>
      </section>
    </main>
  );
}
