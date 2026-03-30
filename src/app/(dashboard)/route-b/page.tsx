import { getRouteBOverview } from "@/features/route-b/queries";

export const dynamic = "force-dynamic";

export default async function RouteBPage() {
  const data = await getRouteBOverview();

  return (
    <div className="grid gap-6">
      <section className="grid gap-4 md:grid-cols-3">
        <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/80 p-5">
          <div className="text-sm font-medium text-stone-500">真实项目</div>
          <div className="mt-3 text-3xl font-semibold text-stone-900">
            {data.totals.projectCount}
          </div>
          <div className="mt-2 text-sm leading-6 text-stone-600">
            直接来自 eLabFTW MySQL 上的 `smartlab_projects`
          </div>
        </div>
        <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/80 p-5">
          <div className="text-sm font-medium text-stone-500">真实样本</div>
          <div className="mt-3 text-3xl font-semibold text-stone-900">
            {data.totals.sampleCount}
          </div>
          <div className="mt-2 text-sm leading-6 text-stone-600">
            已从“通用资源”外独立出样本实体
          </div>
        </div>
        <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/80 p-5">
          <div className="text-sm font-medium text-stone-500">真实审核流</div>
          <div className="mt-3 text-3xl font-semibold text-stone-900">
            {data.totals.reviewCount}
          </div>
          <div className="mt-2 text-sm leading-6 text-stone-600">
            已不再只是 request action 占位
          </div>
        </div>
      </section>

      <section className="rounded-[1.75rem] border border-stone-300/80 bg-white/80 p-6 shadow-[0_18px_50px_-38px_rgba(63,52,36,0.45)] backdrop-blur">
        <div className="border-b border-stone-200 pb-4">
          <h3 className="text-lg font-semibold text-stone-900">
            Route B 项目中枢
          </h3>
          <p className="mt-2 text-sm leading-6 text-stone-600">
            这页不是读 SQLite demo，而是直接读取新部署 eLabFTW 数据库里的
            SmartLab 扩展表。
          </p>
        </div>

        <div className="mt-5 space-y-4">
          {data.projects.map((project) => (
            <article
              key={project.id}
              className="rounded-[1.5rem] border border-stone-200 bg-stone-50/75 p-5"
            >
              <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                  <div className="text-xs font-semibold tracking-[0.2em] text-stone-500 uppercase">
                    {project.code}
                  </div>
                  <h4 className="mt-2 text-lg font-semibold text-stone-900">
                    {project.name}
                  </h4>
                  <p className="mt-2 text-sm leading-6 text-stone-600">
                    {project.description || "暂无说明"}
                  </p>
                </div>
                <div className="grid gap-2 text-sm text-stone-700">
                  <div className="rounded-2xl bg-white px-4 py-3">
                    成员 {project.member_count}
                  </div>
                  <div className="rounded-2xl bg-white px-4 py-3">
                    关联实体 {project.entity_count}
                  </div>
                </div>
              </div>
            </article>
          ))}
        </div>
      </section>

      <div className="grid gap-6 lg:grid-cols-2">
        <section className="rounded-[1.75rem] border border-stone-300/80 bg-white/80 p-6 shadow-[0_18px_50px_-38px_rgba(63,52,36,0.45)] backdrop-blur">
          <div className="border-b border-stone-200 pb-4">
            <h3 className="text-lg font-semibold text-stone-900">样本实体</h3>
            <p className="mt-2 text-sm leading-6 text-stone-600">
              这部分已经从 eLabFTW 的 `items` 语义中拆出来了。
            </p>
          </div>
          <div className="mt-5 space-y-3">
            {data.samples.map((sample) => (
              <div
                key={sample.id}
                className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-4 text-sm text-stone-700"
              >
                <div className="font-semibold text-stone-900">
                  {sample.code} · {sample.name}
                </div>
                <div className="mt-2">
                  类型 {sample.sample_type}，状态 {sample.status}
                </div>
                <div className="mt-1">
                  数量 {sample.quantity ?? "-"} {sample.unit ?? ""}
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-[1.75rem] border border-stone-300/80 bg-white/80 p-6 shadow-[0_18px_50px_-38px_rgba(63,52,36,0.45)] backdrop-blur">
          <div className="border-b border-stone-200 pb-4">
            <h3 className="text-lg font-semibold text-stone-900">审核流</h3>
            <p className="mt-2 text-sm leading-6 text-stone-600">
              这里是 SmartLab 新增审核流，不再只依赖上游轻量请求动作。
            </p>
          </div>
          <div className="mt-5 space-y-3">
            {data.reviews.map((review) => (
              <div
                key={review.id}
                className="rounded-2xl border border-stone-200 bg-stone-50/70 px-4 py-4 text-sm text-stone-700"
              >
                <div className="flex items-center justify-between gap-3">
                  <div className="font-semibold text-stone-900">
                    {review.entity_type} #{review.entity_id}
                  </div>
                  <div className="rounded-full bg-stone-900 px-3 py-1 text-xs font-semibold text-stone-50">
                    {review.status}
                  </div>
                </div>
                <div className="mt-2">提交人 {review.requester_name}</div>
                <div className="mt-1">审核人 {review.assignee_name ?? "未分配"}</div>
                <div className="mt-1 text-stone-600">
                  {review.comment ?? "暂无批注"}
                </div>
              </div>
            ))}
          </div>
        </section>
      </div>
    </div>
  );
}
