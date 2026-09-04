import { authed } from "@/lib/api";
import { PageHead } from "@/components/ds/app-shell";
import { Card, CardBody, Badge, EmptyState } from "@/components/ds/primitives";
import { Kpi } from "@/components/ds/controls";
import { dateTime } from "@/lib/format";

export const metadata = { title: "Calendar" };
const LABEL: Record<string, string> = { tender_closing: "Tender closing", opening_ceremony: "Opening ceremony", evaluation_pending: "Evaluation pending", standstill_ending: "Standstill ending", contract_expiring: "Contract expiring" };
const TONE: Record<string, "brand" | "warn" | "ok" | "neutral"> = { tender_closing: "warn", opening_ceremony: "brand", evaluation_pending: "neutral", standstill_ending: "warn", contract_expiring: "warn" };

export default async function CalendarPage() {
  const res = await authed("/api/v1/authority/calendar");
  const events: any[] = res.body?.data ?? [];
  const b = res.body?.meta?.buckets ?? {};
  return (
    <>
      <PageHead title="Calendar" sub="Deadlines and actions across your tenders — closings, opening ceremonies, standstill windows and contract expiries." />
      <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Kpi label="Closing today" value={b.closing_today ?? 0} tone={(b.closing_today ?? 0) > 0 ? "bad" : "neutral"} />
        <Kpi label="Within 3 days" value={b.within_3_days ?? 0} tone={(b.within_3_days ?? 0) > 0 ? "warn" : "neutral"} />
        <Kpi label="Within 7 days" value={b.within_7_days ?? 0} />
        <Kpi label="Future" value={b.future ?? 0} />
      </div>
      <Card><CardBody>
        {events.length ? (
          <ol className="divide-y divide-ink-100">
            {events.map((e, i) => (
              <li key={i} className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0">
                <span className="flex items-center gap-2.5 min-w-0">
                  <Badge tone={TONE[e.type] ?? "neutral"}>{LABEL[e.type] ?? e.type}</Badge>
                  <span className="truncate text-[13px] text-ink-800">{e.title ?? e.ref}</span>
                  <span className="font-mono text-[11px] text-ink-400 shrink-0">{e.ref}</span>
                </span>
                <span className="font-mono text-[12px] text-ink-500 shrink-0">{e.at ? dateTime(e.at) : "—"}</span>
              </li>
            ))}
          </ol>
        ) : <EmptyState title="Nothing scheduled" help="Deadlines from your tenders and contracts will appear here." />}
      </CardBody></Card>
    </>
  );
}
