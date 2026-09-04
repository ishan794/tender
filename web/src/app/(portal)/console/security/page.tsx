import { authed } from "@/lib/api";
import { PageHead } from "@/components/ds/app-shell";
import { Card, CardBody, Badge, EmptyState } from "@/components/ds/primitives";
import { Kpi } from "@/components/ds/controls";
import { dateTime } from "@/lib/format";

export const metadata = { title: "Security centre" };
const TONE: Record<string, "warn" | "bad" | "neutral" | "ok"> = { info: "neutral", warning: "warn", critical: "bad" };

export default async function SecurityPage() {
  const res = await authed("/api/v1/admin/security/events");
  const rows: any[] = res.body?.data ?? [];
  const sum = res.body?.meta?.summary_24h ?? {};
  return (
    <>
      <PageHead title="Security centre" sub="Security-relevant events across the platform: authentication failures, authorisation refusals and token anomalies." />
      <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        {Object.keys(sum).length ? Object.entries(sum).map(([k, v]) => <Kpi key={k} label={k.replace(/_/g, " ")} value={v as number} tone="warn" />)
          : <Kpi label="Events (24h)" value={0} tone="ok" />}
      </div>
      <Card><CardBody>
        {rows.length ? (
          <ol className="divide-y divide-ink-100">
            {rows.map((e) => (
              <li key={e.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                <span className="flex items-center gap-2 min-w-0">
                  <Badge tone={TONE[e.severity] ?? "neutral"}>{e.kind.replace(/_/g, " ")}</Badge>
                  <span className="truncate text-[12px] text-ink-600">{e.detail ?? "—"}</span>
                </span>
                <span className="font-mono text-[11px] text-ink-400 shrink-0">{e.ip ?? ""} · {dateTime(e.created_at)}</span>
              </li>
            ))}
          </ol>
        ) : <EmptyState title="No security events" help="Failed logins and authorisation refusals will appear here." />}
      </CardBody></Card>
    </>
  );
}
