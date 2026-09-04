"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Card, CardHead, CardBody, Badge, KeyValue, EmptyState } from "@/components/ds/primitives";
import { Button } from "@/components/ds/controls";
import { Toast } from "@/components/ds/overlay";
import { lkr, dateTime } from "@/lib/format";

const SIGN_EVENTS = ["approval", "publication", "addendum", "opening", "award", "contract"];

export function PerTenderPanels(p: {
  id: number; canSign: boolean; estimatedValue: number; signatures: any[]; tco: any | null;
}) {
  const router = useRouter();
  const [toast, setToast] = useState<string | null>(null);

  async function post(path: string, body?: any, ok?: string) {
    const res = await fetch(`/api/workspace/authority/${path}`, {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body ?? {}),
    });
    const j = await res.json().catch(() => ({}));
    setToast(res.ok ? (ok ?? "Done.") : (j.detail ?? "That action was refused."));
    if (res.ok) router.refresh();
    return { ok: res.ok, body: j };
  }

  return (
    <>
      <CompliancePanel value={p.estimatedValue} />

      <Card className="mt-6">
        <CardHead title="Digital signatures" sub="Tamper-evident attestations bound to the signer, the time and a hash of the tender's state." />
        <CardBody>
          {p.canSign ? (
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <span className="text-[12px] text-ink-500">Sign this tender as:</span>
              {SIGN_EVENTS.map((ev) => (
                <Button key={ev} size="sm" variant="secondary" onClick={() => post(`tenders/${p.id}/sign`, { event: ev }, `Signed: ${ev}.`)}>{ev}</Button>
              ))}
            </div>
          ) : <p className="mb-3 text-[12px] text-ink-400">You do not have permission to sign.</p>}
          {p.signatures.length ? (
            <ol className="divide-y divide-ink-100">
              {p.signatures.map((s) => (
                <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                  <span className="flex items-center gap-2 min-w-0">
                    <Badge tone="brand">{s.event}</Badge>
                    <span className="text-[13px] text-ink-800">{s.signer_name ?? "—"}{s.signer_role ? ` (${s.signer_role})` : ""}</span>
                    <Badge tone={s.verified ? "ok" : "bad"} mono>{s.verified ? "verified" : "invalid"}</Badge>
                  </span>
                  <span className="font-mono text-[11px] text-ink-400 shrink-0" title={s.doc_hash}>{(s.doc_hash ?? "").slice(0, 12)}… · {dateTime(s.signed_at)}</span>
                </li>
              ))}
            </ol>
          ) : <EmptyState title="Not signed yet" help="Signing an event records who signed, when, and a hash of the tender state." />}
        </CardBody>
      </Card>

      <Card className="mt-6">
        <CardHead title="Life-cycle cost" sub="Total cost of ownership beyond the purchase price (NPC LCC guidance)." />
        <CardBody>
          {p.tco ? (
            <>
              <KeyValue items={Object.entries(p.tco.components ?? {}).map(([k, v]) => [k.replace(/_/g, " "), <span key={k} className="font-mono">{lkr(Number(v))}</span>] as [string, React.ReactNode])} />
              <div className="mt-3 flex items-center justify-between border-t border-ink-200 pt-3">
                <span className="text-[13px] font-medium text-ink-900">Life-cycle cost</span>
                <span className="font-mono text-[15px] font-semibold text-ink-900">{lkr(Number(p.tco.total))}</span>
              </div>
            </>
          ) : <TcoForm onSubmit={async (body) => { const r = await post(`tenders/${p.id}/tco`, body, "Life-cycle cost saved."); return r.ok; }} />}
        </CardBody>
      </Card>

      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}

function CompliancePanel({ value }: { value: number }) {
  const [data, setData] = useState<any>(null);
  useEffect(() => {
    let live = true;
    fetch("/api/workspace/authority/rules/evaluate", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ value }) })
      .then((r) => r.json()).then((j) => { if (live) setData(j.data); }).catch(() => {});
    return () => { live = false; };
  }, [value]);
  const req = data?.requirements;
  return (
    <Card className="mt-6">
      <CardHead title="Compliance check" sub="Requirements the rule matrix derives from this tender's value — evaluated by the API, not the browser."
        right={data ? <Badge tone={data.compliant ? "ok" : "warn"}>{data.compliant ? "compliant" : "review"}</Badge> : null} />
      <CardBody>
        {req ? (
          <KeyValue items={[
            ["Permitted methods", <span key="m" className="text-[13px]">{req.permitted_methods.join(", ")}</span>],
            ["Approval authority", <span key="a" className="text-[13px]">{req.approval_authority.replace(/_/g, " ")}</span>],
            ["Committee", <span key="c" className="text-[13px]">{req.committee ? req.committee.replace(/_/g, " ") : "—"}</span>],
            ["Bid security", <span key="b" className="font-mono text-[13px]">{lkr(req.bid_security_amount)} ({req.bid_security_pct}%)</span>],
            ["Standstill", <span key="s" className="text-[13px]">{req.standstill_days} days</span>],
            ["Mandatory documents", <span key="d" className="text-[13px]">{req.mandatory_documents.join(", ")}</span>],
          ]} />
        ) : <p className="text-[12px] text-ink-400">Evaluating…</p>}
      </CardBody>
    </Card>
  );
}

function TcoForm({ onSubmit }: { onSubmit: (body: any) => Promise<boolean> }) {
  const lines = ["acquisition", "installation", "operating", "maintenance", "energy", "replacement", "disposal"];
  return (
    <form onSubmit={async (e) => { e.preventDefault(); const f = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement).entries()) as any; const body: any = {}; lines.forEach((l) => (body[l] = Number(f[l] || 0))); await onSubmit(body); }}
      className="grid grid-cols-2 gap-3 sm:grid-cols-3">
      {lines.map((l) => (
        <label key={l} className="block">
          <span className="mb-1 block text-[11px] font-medium capitalize text-ink-600">{l}</span>
          <input name={l} type="number" defaultValue={0} className="h-[34px] w-full rounded-[8px] border border-ink-300 px-2 text-[13px]" />
        </label>
      ))}
      <div className="col-span-2 sm:col-span-3"><Button type="submit">Calculate &amp; save</Button></div>
    </form>
  );
}
