import { authed } from "@/lib/api";
import { PageHead } from "@/components/ds/app-shell";
import { Card, CardBody, Badge, EmptyState } from "@/components/ds/primitives";

export const metadata = { title: "Risk signals" };
const TONE: Record<string, "warn" | "bad" | "neutral"> = { low: "neutral", medium: "warn", high: "bad" };

export default async function RiskPage() {
  const res = await authed("/api/v1/admin/risk-signals");
  const rows: any[] = res.body?.data ?? [];
  return (
    <>
      <PageHead title="Risk signals" sub="Patterns worth a human's attention — these are review signals, not determinations of wrongdoing." />
      <Card><CardBody>
        {rows.length ? (
          <ol className="divide-y divide-ink-100">
            {rows.map((s, i) => (
              <li key={i} className="flex flex-wrap items-start justify-between gap-2 py-3 first:pt-0 last:pb-0">
                <span className="min-w-0">
                  <span className="flex items-center gap-2">
                    <Badge tone={TONE[s.severity] ?? "neutral"}>{s.severity}</Badge>
                    <span className="text-[13px] font-medium text-ink-900">{s.signal.replace(/_/g, " ")}</span>
                  </span>
                  <p className="mt-1 text-[12px] text-ink-500">{s.reason}</p>
                </span>
                <span className="font-mono text-[11px] text-ink-400 shrink-0">{s.ref}</span>
              </li>
            ))}
          </ol>
        ) : <EmptyState title="No signals" help="Nothing currently meets a review threshold." />}
      </CardBody></Card>
    </>
  );
}
