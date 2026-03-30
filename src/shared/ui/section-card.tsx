export function SectionCard({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-[1.75rem] border border-stone-300/80 bg-white/80 p-6 shadow-[0_18px_50px_-38px_rgba(63,52,36,0.45)] backdrop-blur">
      <div className="border-b border-stone-200 pb-4">
        <h3 className="text-lg font-semibold text-stone-900">{title}</h3>
        {description ? (
          <p className="mt-2 text-sm leading-6 text-stone-600">{description}</p>
        ) : null}
      </div>
      <div className="pt-5">{children}</div>
    </section>
  );
}
