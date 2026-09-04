import { apiFetch } from "@/lib/api";
import { lkr } from "@/lib/format";

export const metadata = {
  title: "Procurement transparency — Sri Lanka",
  description: "Open procurement statistics: published notices, awarded values and activity by district.",
  alternates: { canonical: "/transparency" },
};

export default async function TransparencyPage() {
  const res = await apiFetch("/api/v1/transparency");
  const d: any = res.body?.data ?? {};
  const byDistrict: any[] = d.awards_by_district ?? [];
  const Stat = ({ label, value }: { label: string; value: React.ReactNode }) => (
    <div className="rounded-2xl border border-slate-200 bg-white p-5">
      <p className="text-[11px] font-bold uppercase tracking-wider text-slate-500">{label}</p>
      <p className="mt-2 font-display text-3xl font-black text-[#0F172A]">{value}</p>
    </div>
  );
  return (
    <div className="mx-auto max-w-[1100px] px-5 py-12">
      <h1 className="font-display text-4xl font-black uppercase tracking-tight text-[#0F172A]">Procurement transparency</h1>
      <p className="mt-2 max-w-2xl text-sm text-slate-600">Aggregate, public figures only. Sealed bids, bidder identities and private documents are never shown here.</p>
      <div className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <Stat label="Published notices" value={d.published_notices ?? 0} />
        <Stat label="Awarded value" value={lkr(d.total_awarded_value ?? 0)} />
        <Stat label="Organisations" value={d.organisations ?? 0} />
        <Stat label="Suppliers" value={d.suppliers ?? 0} />
        <Stat label="Open" value={d.open_notices ?? 0} />
        <Stat label="Closed" value={d.closed_notices ?? 0} />
      </div>
      <h2 className="mt-10 font-display text-2xl font-black uppercase tracking-tight text-[#0F172A]">Awards by district</h2>
      <div className="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table className="w-full min-w-[420px] text-sm">
          <thead><tr className="border-b border-slate-200 text-left text-[11px] uppercase tracking-wider text-slate-500">
            <th className="px-5 py-3">District</th><th className="px-5 py-3 text-right">Awards</th><th className="px-5 py-3 text-right">Value</th></tr></thead>
          <tbody>
            {byDistrict.length ? byDistrict.map((r, i) => (
              <tr key={i} className="border-b border-slate-100 last:border-0">
                <td className="px-5 py-3">{r.district ?? "—"}</td>
                <td className="px-5 py-3 text-right font-mono">{r.awards}</td>
                <td className="px-5 py-3 text-right font-mono">{lkr(Number(r.value))}</td>
              </tr>
            )) : <tr><td colSpan={3} className="px-5 py-8 text-center text-slate-400">No completed awards to show yet.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  );
}
