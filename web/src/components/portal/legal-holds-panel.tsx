"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { Card, CardHead, CardBody, Badge, EmptyState } from "@/components/ds/primitives";
import { Button } from "@/components/ds/controls";
import { Toast } from "@/components/ds/overlay";
import { dateTime } from "@/lib/format";

export function LegalHoldsPanel({ rows }: { rows: any[] }) {
  const router = useRouter();
  const [toast, setToast] = useState<string | null>(null);
  async function call(method: string, path: string, body?: any, ok?: string) {
    const res = await fetch(`/api/workspace/admin/${path}`, { method, headers: { "Content-Type": "application/json" }, body: body ? JSON.stringify(body) : undefined });
    const j = await res.json().catch(() => ({}));
    setToast(res.ok ? (ok ?? "Done.") : (j.detail ?? "Refused."));
    if (res.ok) router.refresh();
  }
  return (
    <>
      <Card className="mb-5">
        <CardHead title="Place a legal hold" sub="The held record cannot be deleted until the hold is released." />
        <CardBody>
          <form className="flex flex-wrap items-end gap-3" onSubmit={async (e) => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement).entries()) as any; await call("POST", "legal-holds", { entity_type: f.entity_type, entity_id: Number(f.entity_id), reason: f.reason }, "Hold placed."); (e.currentTarget as HTMLFormElement).reset(); }}>
            <label className="block"><span className="mb-1 block text-[12px] font-medium text-ink-600">Entity type</span>
              <select name="entity_type" className="h-[38px] rounded-[8px] border border-ink-300 px-2.5 text-[13px]"><option value="procurement">procurement</option><option value="complaint">complaint</option><option value="contract">contract</option></select></label>
            <label className="block"><span className="mb-1 block text-[12px] font-medium text-ink-600">Entity id</span>
              <input name="entity_id" type="number" required className="h-[38px] w-24 rounded-[8px] border border-ink-300 px-2.5 text-[13px]" /></label>
            <label className="block flex-1 min-w-[200px]"><span className="mb-1 block text-[12px] font-medium text-ink-600">Reason</span>
              <input name="reason" required className="h-[38px] w-full rounded-[8px] border border-ink-300 px-2.5 text-[13px]" /></label>
            <Button type="submit">Place hold</Button>
          </form>
        </CardBody>
      </Card>
      <Card>
        <CardHead title="Active holds" />
        <CardBody>
          {rows.length ? (
            <ol className="divide-y divide-ink-100">
              {rows.map((h) => (
                <li key={h.id} className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0">
                  <span className="min-w-0">
                    <span className="flex items-center gap-2"><Badge tone="warn">{h.entity_type} #{h.entity_id}</Badge><span className="text-[13px] text-ink-800">{h.reason}</span></span>
                    <span className="text-[11px] text-ink-400">by {h.created_name ?? "staff"} · {dateTime(h.created_at)}</span>
                  </span>
                  <Button size="sm" variant="secondary" onClick={() => call("POST", `legal-holds/${h.id}/release`, {}, "Hold released.")}>Release</Button>
                </li>
              ))}
            </ol>
          ) : <EmptyState title="No active holds" />}
        </CardBody>
      </Card>
      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}
