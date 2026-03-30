export function StatCard({
  label,
  value,
  hint,
}: {
  label: string;
  value: string;
  hint: string;
}) {
  return (
    <div className="rounded-[1.5rem] border border-stone-200 bg-stone-50/80 p-5">
      <div className="text-sm font-medium text-stone-500">{label}</div>
      <div className="mt-3 text-3xl font-semibold text-stone-900">{value}</div>
      <div className="mt-2 text-sm leading-6 text-stone-600">{hint}</div>
    </div>
  );
}
