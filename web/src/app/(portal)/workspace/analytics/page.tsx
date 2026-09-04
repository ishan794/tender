import { authed } from "@/lib/api";
import { PageHead } from "@/components/ds/app-shell";
import { Card, CardBody } from "@/components/ds/primitives";
import { Kpi } from "@/components/ds/controls";
import { lkr } from "@/lib/format";

export const metadata = { title: "Analytics" };

export default async function AnalyticsPage() {
  const res = await authed("/api/v1/authority/analytics");
  const d = res.body?.data ?? {};
  const pct = (v: number) => `${Math.round((v ?? 0) * 100)}%`;
  return (
    <>
      <PageHead title="Analytics" sub="Live figures from your procurement record — every number is queried from the database, not hardcoded." />
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        <Kpi label="Tenders (total)" value={d.tenders_total ?? 0} />
        <Kpi label="Published" value={d.tenders_published ?? 0} />
        <Kpi label="Awarded" value={d.tenders_awarded ?? 0} tone="ok" />
        <Kpi label="Estimated value" value={lkr(d.total_estimated_value ?? 0)} />
        <Kpi label="Award rate" value={pct(d.award_rate)} />
        <Kpi label="Avg bidders / tender" value={d.avg_bidders_per_tender ?? 0} />
        <Kpi label="Competition rate" value={pct(d.competition_rate)} />
        <Kpi label="Amendment rate" value={pct(d.amendment_rate)} />
      </div>
      <Card className="mt-4"><CardBody className="text-[12px] text-ink-400">Source: live database aggregate · {res.body?.meta?.server_now}</CardBody></Card>
    </>
  );
}
