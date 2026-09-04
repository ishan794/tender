"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Badge, Card, CardBody, CardHead, EmptyState, KeyValue } from "@/components/ds/primitives";
import { Button } from "@/components/ds/controls";
import { Modal, ConfirmDialog, Toast } from "@/components/ds/overlay";
import { Tabs, Stepper } from "@/components/ds/nav";
import { DocumentList } from "@/components/portal/document-list";
import { lkr, bytes, dateTime, date } from "@/lib/format";
import type { Submission } from "@/lib/types";

const STAGES = ["Draft", "Approval", "Published", "Closed", "Opened", "Evaluation", "Award"];

/** Friendly labels for ledger event types. Unknown types fall back to the raw key. */
const LEDGER_LABELS: Record<string, string> = {
  "tender.submitted": "Submitted for approval",
  "tender.approved": "Approved",
  "tender.published": "Published",
  "addendum.issued": "Addendum issued",
  "opening.started": "Opening started",
  "opening.countersigned": "Opening countersigned",
  "award.created": "Awarded",
};

/** Complaint status → label, badge tone, and the transitions available from it. */
const CMP_LABEL: Record<string, string> = {
  submitted: "Submitted", acknowledged: "Acknowledged", under_review: "Under review",
  response_requested: "Response requested", decision: "Decided", appeal: "Under appeal", closed: "Closed",
};
const CMP_TONE: Record<string, "neutral" | "brand" | "ok" | "warn" | "bad"> = {
  submitted: "warn", acknowledged: "brand", under_review: "brand",
  response_requested: "warn", decision: "neutral", appeal: "warn", closed: "neutral",
};
const CMP_ACTIONS: Record<string, { key: string; label: string; msg?: string }[]> = {
  submitted: [{ key: "acknowledge", label: "Acknowledge", msg: "Acknowledged." }],
  acknowledged: [{ key: "review", label: "Start review", msg: "Under review." }],
  under_review: [{ key: "request_response", label: "Request response", msg: "Response requested." }, { key: "decide", label: "Record decision…" }],
  response_requested: [{ key: "decide", label: "Record decision…" }],
  appeal: [{ key: "decide", label: "Record decision…" }, { key: "close", label: "Close", msg: "Closed." }],
  decision: [{ key: "close", label: "Close", msg: "Closed." }],
  closed: [],
};

export function TenderWorkspace(p: {
  proc: any; me: number; opened: boolean; submissions: Submission[];
  withheld: string[]; withheldReason: string; opensAt: string | null;
  documents: any[]; clarifications: any[]; addenda: any[];
  purchasers: any[]; purchaseMeta: any; award: any; awardMeta: any;
  ledger: any[]; ledgerIntegrity: { ok: boolean; count: number; broken_at: number | null } | null;
  complaints: any[];
}) {
  const router = useRouter();
  const [tab, setTab] = useState("overview");
  const [toast, setToast] = useState<{ m: string; t: "ok" | "bad" } | null>(null);
  const [confirm, setConfirm] = useState<null | { title: string; body: string; run: () => void }>(null);
  const [addendumOpen, setAddendumOpen] = useState(false);
  const [deciding, setDeciding] = useState<number | null>(null);
  const id = p.proc.id;

  // Complaint transitions go to /authority/complaints/:id, not under this tender.
  async function actComplaint(cid: number, action: string, extra?: any, okMsg?: string) {
    const res = await fetch(`/api/workspace/authority/complaints/${cid}/transition`, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action, ...(extra ?? {}) }),
    });
    const json = await res.json();
    if (!res.ok) {
      setToast({ m: json.detail ?? "That action is not allowed right now.", t: "bad" });
      return false;
    }
    setToast({ m: okMsg ?? "Done.", t: "ok" });
    router.refresh();
    return true;
  }

  async function act(path: string, body?: any, okMsg?: string) {
    const res = await fetch(`/api/workspace/authority/tenders/${id}${path}`, {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body ?? {}),
    });
    const json = await res.json();
    if (!res.ok) {
      // The API's refusal is shown verbatim, with its remedy. "Forbidden" with
      // no reason turns into a telephone call every single time.
      setToast({ m: `${json.detail}${json.remedy ? " " + json.remedy : ""}`, t: "bad" });
      return false;
    }
    setToast({ m: okMsg ?? "Done.", t: "ok" });
    router.refresh();
    return true;
  }

  const stage = p.proc.stage_idx as number;

  return (
    <>
      <div className="mb-5">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-mono text-[11px] text-ink-400">{p.proc.reference}</span>
          <Badge tone={stage >= 6 ? "ok" : stage === 0 ? "neutral" : "brand"}>{STAGES[stage]}</Badge>
        </div>
        <h1 className="mt-1.5 text-[22px] font-semibold tracking-tight text-ink-900">{p.proc.title}</h1>
        <div className="mt-3"><Stepper steps={STAGES} current={stage} /></div>
      </div>

      <Card>
        <Tabs
          value={tab} onChange={setTab}
          tabs={[
            { key: "overview", label: "Overview" },
            { key: "documents", label: "Documents", n: p.documents.length },
            { key: "purchasers", label: "Purchasers", n: p.purchasers.length },
            { key: "clarifications", label: "Clarifications", n: p.clarifications.length },
            { key: "addenda", label: "Addenda", n: p.addenda.length },
            { key: "submissions", label: "Bids", n: p.submissions.length },
            { key: "award", label: "Award" },
          ]}
        />

        {tab === "overview" ? (
          <CardBody>
            <KeyValue items={[
              ["Estimated value", <span key="v" className="font-mono">{lkr(p.proc.estimated_value)}</span>],
              ["Closes", <span key="c" className="font-mono">{dateTime(p.proc.closing_at)}</span>],
              ["Bids opened", <span key="o" className="font-mono">{dateTime(p.proc.opening_at)}</span>],
              ["Approved", p.proc.approved_at ? `${dateTime(p.proc.approved_at)} by user #${p.proc.approved_by}` : "Not approved"],
              ["Published", p.proc.published_at ? dateTime(p.proc.published_at) : "Not published"],
              ["Opening officers", p.proc.opened_by_a ? `#${p.proc.opened_by_a} and #${p.proc.opened_by_b ?? "—"}` : "—"],
            ]} />

            <div className="mt-6 flex flex-wrap gap-2 border-t border-ink-200 pt-5">
              {stage === 0 ? <Button onClick={() => act("/submit-for-approval", {}, "Sent for approval.")}>Submit for approval</Button> : null}
              {stage === 1 && !p.proc.approved_at ? (
                <Button onClick={() => act("/approve", {}, "Approved. Publishing is a separate act.")}>Approve</Button>
              ) : null}
              {p.proc.approved_at && stage < 2 ? (
                <Button onClick={() => setConfirm({
                  title: "Publish this tender?",
                  body: "It becomes visible on the public site immediately, and the closing date can then only be moved by a numbered addendum.",
                  run: () => act("/publish", {}, "Published."),
                })}>Publish</Button>
              ) : null}
              {stage === 2 && !p.opened ? (
                <>
                  <Button variant="secondary" onClick={() => act("/opening/start", {}, "Opening started. A different officer must now countersign.")}>
                    Start the opening ceremony
                  </Button>
                  <Button onClick={() => act("/opening/countersign", {}, "Countersigned. Bids are now readable by the committee.")}>
                    Countersign the opening
                  </Button>
                </>
              ) : null}
              <a href={`/api/workspace/authority/tenders/${id}/evidence`} target="_blank" rel="noreferrer"
                 className="inline-flex h-[var(--ctl-h)] items-center rounded-[8px] px-3.5 text-[13px] font-medium text-ink-600 ring-1 ring-inset ring-ink-300 hover:bg-ink-50">
                Evidence pack
              </a>
            </div>

            <p className="mt-4 text-[12px] leading-relaxed text-ink-400">
              Above your organisation&rsquo;s threshold the creator of a tender cannot approve it, and the officer who
              starts an opening cannot countersign it. Both are refused by the API — a rule that only exists in the
              interface is not a control, it is a suggestion.
            </p>
          </CardBody>
        ) : null}

        {tab === "documents" ? (
          <>
            <DocumentList noticeId={p.proc.notice_id} workspace={id} documents={p.documents.map((d: any) => ({
              id: d.id, name: d.name, kind: d.kind, size_bytes: d.size_bytes, sha256: d.sha256,
              available: !!d.mirrored_at, reason: null, source_url: d.source_url,
            }))} />
            <CardBody className="border-t border-ink-200 text-[12px] text-ink-400">
              Uploads are refused once the tender closes — adding a document then changes what bidders were asked to
              price after they priced it. That is what an addendum is for.
            </CardBody>
          </>
        ) : null}

        {tab === "purchasers" ? (
          p.purchasers.length ? (
            <>
              <div className="grid grid-cols-3 gap-3 border-b border-ink-200 p-[var(--card-p)]">
                {[["Purchasers", p.purchaseMeta.purchasers], ["Submissions", p.purchaseMeta.submissions],
                  ["Conversion", p.purchaseMeta.conversion !== null ? `${p.purchaseMeta.conversion}%` : "—"]].map(([l, v]) => (
                  <div key={l as string}>
                    <p className="text-[11px] uppercase tracking-wide text-ink-400">{l as string}</p>
                    <p className="mt-0.5 font-mono text-[20px] font-semibold text-ink-900">{v as any}</p>
                  </div>
                ))}
              </div>
              <ul>
                {p.purchasers.map((x: any) => (
                  <li key={x.id} className="flex items-center justify-between border-b border-ink-100 px-[var(--card-p)] py-3 last:border-0">
                    <div>
                      <p className="text-[13px] font-medium text-ink-900">{x.name}</p>
                      <p className="row-meta font-mono text-[11px] text-ink-400">{x.receipt_no} · {x.cida_grade ?? "—"}</p>
                    </div>
                    <span className="font-mono text-[12px] text-ink-500">{dateTime(x.purchased_at)}</span>
                  </li>
                ))}
              </ul>
              <CardBody className="border-t border-ink-200 text-[12px] text-ink-400">
                This register is the legal record of who is entitled to bid. Export it for the file before the opening.
              </CardBody>
            </>
          ) : <EmptyState title="Nobody has bought the documents yet" />
        ) : null}

        {tab === "clarifications" ? (
          p.clarifications.length ? (
            <ul>
              {p.clarifications.map((c: any) => (
                <li key={c.id} className="border-b border-ink-100 px-[var(--card-p)] py-4 last:border-0">
                  <p className="text-[13px] text-ink-800">{c.question}</p>
                  {c.answer ? (
                    <p className="mt-2 rounded-[8px] bg-ok-50 px-3 py-2 text-[13px] text-ink-700">{c.answer}</p>
                  ) : (
                    <AnswerBox id={id} clarId={c.id} onDone={(m, t) => { setToast({ m, t }); router.refresh(); }} />
                  )}
                  <p className="mt-1.5 text-[11px] text-ink-400">
                    Asked {date(c.created_at)} · the asker is never named
                  </p>
                </li>
              ))}
            </ul>
          ) : <EmptyState title="No questions yet" help="Answers are published to every purchaser at once, anonymously." />
        ) : null}

        {tab === "addenda" ? (
          <>
            <div className="flex justify-end border-b border-ink-200 px-[var(--card-p)] py-3">
              <Button size="sm" onClick={() => setAddendumOpen(true)}>Issue an addendum</Button>
            </div>
            {p.addenda.length ? (
              <ul>
                {p.addenda.map((a: any) => (
                  <li key={a.id} className="border-b border-ink-100 px-[var(--card-p)] py-3.5 last:border-0">
                    <div className="flex items-center gap-2">
                      <Badge tone="brand">Addendum {a.number}</Badge>
                      {a.new_closing_at ? <span className="font-mono text-[12px] text-ink-600">closing moved to {dateTime(a.new_closing_at)}</span> : null}
                    </div>
                    <p className="mt-1.5 text-[13px] text-ink-700">{a.reason}</p>
                  </li>
                ))}
              </ul>
            ) : <EmptyState title="No addenda" help="An addendum is the only way a published closing date moves — and it can only be extended, never brought forward." />}
          </>
        ) : null}

        {tab === "submissions" ? (
          <>
            {!p.opened ? (
              <div className="border-b border-ink-200 bg-warn-50 px-[var(--card-p)] py-3">
                <p className="text-[13px] font-medium text-warn-600">Sealed — {p.withheld.join(", ")} withheld</p>
                <p className="mt-1 text-[12px] leading-relaxed text-ink-600">{p.withheldReason}</p>
                {p.opensAt ? <p className="mt-1 font-mono text-[12px] text-ink-500">Opens {dateTime(p.opensAt)}</p> : null}
              </div>
            ) : null}

            {p.submissions.length ? (
              <div className="overflow-x-auto custom-scrollbar">
                <table className="w-full text-left min-w-[500px]">
                  <thead>
                    <tr className="border-b border-ink-200 bg-ink-50 text-[11px] uppercase tracking-wide text-ink-500">
                      <th className="px-[var(--card-p)] py-2 font-semibold">Reference</th>
                      {p.opened ? <th className="px-3 py-2 font-semibold">Bidder</th> : null}
                      {p.opened ? <th className="px-3 py-2 text-right font-semibold">Total price</th> : null}
                      {p.opened ? <th className="px-3 py-2 font-semibold">Security</th> : null}
                      <th className="px-3 py-2 text-right font-semibold">Size</th>
                      <th className="px-[var(--card-p)] py-2 text-right font-semibold">Received</th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.submissions.map((s) => (
                      <tr key={s.id} className="border-b border-ink-100 last:border-0" style={{ height: "var(--row-h)" }}>
                        <td className="px-[var(--card-p)] font-mono text-[12px] text-ink-700">{s.reference}</td>
                        {p.opened ? <td className="px-3 text-[13px] font-medium text-ink-900">{s.bidder_name}</td> : null}
                        {p.opened ? <td className="px-3 text-right font-mono text-[13px] tabular">{lkr(Number(s.total_price))}</td> : null}
                        {p.opened ? <td className="px-3">{s.has_security ? <Badge tone="ok">Lodged</Badge> : <Badge tone="bad">Missing</Badge>}</td> : null}
                        <td className="px-3 text-right font-mono text-[12px] text-ink-500">{bytes(s.size_bytes)}</td>
                        <td className="px-[var(--card-p)] text-right font-mono text-[12px] text-ink-500">{dateTime(s.received_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : <EmptyState title="No bids lodged yet" />}
          </>
        ) : null}

        {tab === "award" ? (
          p.award ? (
            <CardBody>
              <KeyValue items={[
                ["Amount", <span key="a" className="font-mono text-[16px] font-medium">{lkr(Number(p.award.amount))}</span>],
                ["Committee reference", <span key="c" className="font-mono">{p.award.committee_ref}</span>],
                ["Awarded", dateTime(p.award.awarded_at)],
                ["Standstill until", <span key="s" className="font-mono">{dateTime(p.award.standstill_until)}</span>],
              ]} />
              {p.awardMeta.in_standstill ? (
                <p className="mt-4 rounded-[8px] bg-warn-50 px-3 py-2 text-[13px] text-warn-600">
                  In standstill. This award is not publicly listed and cannot be rated until the challenge window
                  closes — the standstill is computed by the server, never accepted from the browser.
                </p>
              ) : (
                <p className="mt-4 rounded-[8px] bg-ok-50 px-3 py-2 text-[13px] text-ok-600">
                  Standstill expired. This award is publicly listed and both parties may now rate each other, once.
                </p>
              )}
            </CardBody>
          ) : (
            <EmptyState
              title={stage < 5 ? "Evaluation has not begun" : "Not yet awarded"}
              help={stage < 5 ? "Bids must be opened and scored before a tender can be awarded." : "Record the committee approval reference to award."}
            />
          )
        ) : null}
      </Card>

      <Card className="mt-6">
        <CardHead
          title="Challenges"
          sub="Formal complaints against this tender. Every step is recorded in the ledger, and a challenge can never be deleted."
        />
        <CardBody>
          {p.complaints.length ? (
            <div className="space-y-3">
              {p.complaints.map((c) => (
                <div key={c.id} className="rounded-[10px] border border-ink-200 p-3.5">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-[12px] text-ink-500">{c.reference}</span>
                    <Badge tone={CMP_TONE[c.status] ?? "neutral"}>{CMP_LABEL[c.status] ?? c.status}</Badge>
                    {c.decision ? (
                      <Badge tone={c.decision === "upheld" ? "ok" : c.decision === "rejected" ? "bad" : "warn"}>{c.decision}</Badge>
                    ) : null}
                  </div>
                  <p className="mt-2 text-[13px] leading-relaxed text-ink-700">{c.grounds}</p>
                  <div className="mt-1 text-[11px] text-ink-400">
                    Filed by {c.complainant_name ?? "a bidder"}
                    {c.response_deadline ? ` · response due ${dateTime(c.response_deadline)}` : ""}
                    {c.decision_reason ? ` · reason: ${c.decision_reason}` : ""}
                  </div>
                  {(CMP_ACTIONS[c.status] ?? []).length ? (
                    <div className="mt-2.5 flex flex-wrap gap-2">
                      {(CMP_ACTIONS[c.status] ?? []).map((a) =>
                        a.key === "decide" ? (
                          <Button key="decide" variant="secondary" onClick={() => setDeciding(deciding === c.id ? null : c.id)}>
                            Record decision…
                          </Button>
                        ) : (
                          <Button key={a.key} variant="secondary" onClick={() => actComplaint(c.id, a.key, undefined, a.msg)}>
                            {a.label}
                          </Button>
                        ),
                      )}
                    </div>
                  ) : null}
                  {deciding === c.id ? (
                    <form
                      onSubmit={async (e) => {
                        e.preventDefault();
                        const f = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement).entries()) as any;
                        const ok = await actComplaint(c.id, "decide", { decision: f.decision, decision_reason: f.decision_reason }, "Decision recorded.");
                        if (ok) setDeciding(null);
                      }}
                      className="mt-3 space-y-2 rounded-[8px] bg-ink-50 p-3"
                    >
                      <select name="decision" required className="h-[34px] w-full rounded-[8px] border border-ink-300 px-2 text-[13px]">
                        <option value="">Decision…</option>
                        <option value="upheld">Upheld</option>
                        <option value="rejected">Rejected</option>
                        <option value="partial">Partially upheld</option>
                      </select>
                      <textarea name="decision_reason" rows={2} required placeholder="Reason — the complainant reads this verbatim"
                        className="w-full rounded-[8px] border border-ink-300 p-2 text-[13px]" />
                      <div className="flex gap-2">
                        <Button type="submit">Record decision</Button>
                        <Button type="button" variant="secondary" onClick={() => setDeciding(null)}>Cancel</Button>
                      </div>
                    </form>
                  ) : null}
                </div>
              ))}
            </div>
          ) : (
            <EmptyState title="No challenges" help="A bidder can formally challenge this tender; any challenge appears here for review." />
          )}
        </CardBody>
      </Card>

      <Card className="mt-6">
        <CardHead
          title="Procurement event ledger"
          sub="Append-only and hash-chained — every material action is recorded and cannot be edited after the fact."
          right={
            p.ledgerIntegrity ? (
              <Badge tone={p.ledgerIntegrity.ok ? "ok" : "bad"} mono>
                {p.ledgerIntegrity.ok ? "Integrity verified" : "Tampering detected"}
              </Badge>
            ) : null
          }
        />
        <CardBody>
          {p.ledger.length ? (
            <ol className="relative ml-2 border-l border-ink-200">
              {p.ledger.map((e) => (
                <li key={e.id} className="mb-4 ml-4 last:mb-0">
                  <span className="absolute -left-[5px] mt-1.5 h-2.5 w-2.5 rounded-full bg-brand-500 ring-2 ring-white" aria-hidden />
                  <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span className="text-[13px] font-medium text-ink-900">{LEDGER_LABELS[e.event_type] ?? e.event_type}</span>
                    <span className="font-mono text-[11px] text-ink-400">{dateTime(e.created_at)}</span>
                  </div>
                  <div className="text-[12px] text-ink-500">
                    {e.summary ? <span>{e.summary} · </span> : null}
                    {e.actor_name
                      ? <span>{e.actor_name}{e.actor_role ? ` (${e.actor_role})` : ""}</span>
                      : <span className="italic">system</span>}
                  </div>
                </li>
              ))}
            </ol>
          ) : (
            <EmptyState
              title="No recorded events yet"
              help="Events appear here as the tender is submitted, approved, published, opened and awarded."
            />
          )}
        </CardBody>
      </Card>

      <Modal open={addendumOpen} onClose={() => setAddendumOpen(false)} title="Issue an addendum" width={540}
        footer={<><Button variant="secondary" onClick={() => setAddendumOpen(false)}>Cancel</Button>
                 <Button form="ad" type="submit">Issue</Button></>}>
        <form id="ad" onSubmit={async (e) => {
          e.preventDefault();
          const f = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement).entries()) as any;
          const ok = await act("/addenda", {
            reason: f.reason,
            new_closing_at: f.new_closing_at ? f.new_closing_at.replace("T", " ") + ":00" : undefined,
          }, "Addendum issued.");
          if (ok) setAddendumOpen(false);
        }} className="space-y-3.5">
          <label className="block">
            <span className="mb-1 block text-[12px] font-medium text-ink-600">Reason — bidders read this verbatim</span>
            <textarea name="reason" rows={3} required className="w-full rounded-[8px] border border-ink-300 p-2.5 text-[13px]" />
          </label>
          <label className="block">
            <span className="mb-1 block text-[12px] font-medium text-ink-600">New closing date (optional)</span>
            <input name="new_closing_at" type="datetime-local" className="h-[38px] w-full rounded-[8px] border border-ink-300 px-2.5 text-[13px]" />
          </label>
          <p className="rounded-[8px] bg-ink-50 px-3 py-2 text-[12px] text-ink-500">
            A closing date can be extended but never brought forward, and the extension and its numbered reason are
            written in one transaction.
          </p>
        </form>
      </Modal>

      {confirm ? (
        <ConfirmDialog open onClose={() => setConfirm(null)} onConfirm={confirm.run} title={confirm.title} body={confirm.body} confirmLabel="Yes, do it" />
      ) : null}
      {toast ? <Toast message={toast.m} tone={toast.t} onDone={() => setToast(null)} /> : null}
    </>
  );
}

function AnswerBox({ id, clarId, onDone }: { id: number; clarId: number; onDone: (m: string, t: "ok" | "bad") => void }) {
  const [v, setV] = useState("");
  const [busy, setBusy] = useState(false);
  return (
    <div className="mt-2 flex gap-2">
      <input value={v} onChange={(e) => setV(e.target.value)} placeholder="Answer, published to every purchaser at once"
        className="h-[34px] flex-1 rounded-[8px] border border-ink-300 px-2.5 text-[13px]" />
      <Button size="sm" disabled={busy || !v.trim()} onClick={async () => {
        setBusy(true);
        const res = await fetch(`/api/workspace/authority/tenders/${id}/clarifications/${clarId}/answer`, {
          method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ answer: v }),
        });
        setBusy(false);
        onDone(res.ok ? "Answered, and published to every purchaser." : "That answer could not be saved.", res.ok ? "ok" : "bad");
      }}>Answer</Button>
    </div>
  );
}
