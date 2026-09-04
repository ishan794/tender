"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { DataTable, type Column } from "@/components/ds/data-table";
import { Badge } from "@/components/ds/primitives";
import { Button, Kpi } from "@/components/ds/controls";
import { Modal, Toast } from "@/components/ds/overlay";
import { lkr, date } from "@/lib/format";

const STATUS_TONE: Record<string, "neutral" | "brand" | "ok" | "warn"> = {
  draft: "neutral", submitted: "warn", approved: "ok", revised: "neutral",
};
const METHODS = ["open", "limited", "rfq", "shopping", "direct", "two_stage", "framework"];

export function PlanningTable({ rows, summary, year }: { rows: any[]; summary: any; year: number }) {
  const router = useRouter();
  const [toast, setToast] = useState<string | null>(null);
  const [open, setOpen] = useState(false);

  async function act(path: string, body?: any, ok?: string) {
    const res = await fetch(`/api/workspace/authority/${path}`, {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body ?? {}),
    });
    const j = await res.json().catch(() => ({}));
    setToast(res.ok ? (ok ?? "Done.") : (j.detail ?? "That action was refused."));
    if (res.ok) router.refresh();
    return res.ok;
  }

  const cols: Column<any>[] = [
    { key: "title", header: "Plan line", sortable: true, sortValue: (r) => r.title,
      cell: (r) => <span className="font-medium text-ink-900">{r.title}</span>,
      meta: (r) => <>{r.department ?? "—"}{r.funding_source ? ` · ${r.funding_source}` : ""} · {r.procurement_method}</> },
    { key: "value", header: "Estimated value", width: "150px", align: "right", sortable: true, sortValue: (r) => Number(r.estimated_value),
      cell: (r) => <span className="font-mono text-[13px]">{lkr(Number(r.estimated_value))}</span>,
      meta: (r) => r.planned_tender_date ? `tender ${date(r.planned_tender_date)}` : "" },
    { key: "status", header: "Status", width: "120px", sortable: true, sortValue: (r) => r.status,
      cell: (r) => <Badge tone={STATUS_TONE[r.status] ?? "neutral"}>{r.status}</Badge>,
      meta: (r) => r.linked_procurement_id ? `→ tender #${r.linked_procurement_id}` : "" },
    { key: "actions", header: "", width: "180px", align: "right",
      cell: (r) => (
        <span className="flex justify-end gap-1.5">
          {r.status === "draft" ? <Button size="sm" variant="secondary" onClick={() => act(`plans/${r.id}/submit`, {}, "Submitted for approval.")}>Submit</Button> : null}
          {r.status === "submitted" ? <Button size="sm" onClick={() => act(`plans/${r.id}/approve`, {}, "Approved.")}>Approve</Button> : null}
          {r.status === "approved" ? <Button size="sm" variant="secondary" onClick={() => act(`plans/${r.id}/revise`, {}, "Revision created.")}>Revise</Button> : null}
        </span>
      ) },
  ];

  return (
    <>
      <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Kpi label={`${year} planned`} value={lkr(summary?.total_planned ?? 0)} />
        <Kpi label="Approved" value={summary?.by_status?.approved ?? 0} tone="ok" />
        <Kpi label="Published value" value={lkr(summary?.published_value ?? 0)} />
        <Kpi label="Delayed" value={summary?.delayed ?? 0} tone={(summary?.delayed ?? 0) > 0 ? "warn" : "neutral"} />
      </div>

      <div className="mb-3 flex justify-end">
        <Button onClick={() => setOpen(true)}>New plan line</Button>
      </div>

      <DataTable rows={rows} columns={cols} searchKeys={(r) => `${r.title} ${r.department ?? ""}`}
        filters={[{ key: "status", label: "Status", options: ["draft", "submitted", "approved"].map((s) => ({ value: s, label: s, n: rows.filter((r) => r.status === s).length })), match: (r, s) => s.includes(r.status) }]}
        empty={{ title: "No plan lines yet", help: "Add a planned procurement to build this year's plan." }} />

      <Modal open={open} onClose={() => setOpen(false)} title="New plan line" width={560}
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button><Button form="pl" type="submit">Add to plan</Button></>}>
        <form id="pl" className="space-y-3.5" onSubmit={async (e) => {
          e.preventDefault();
          const f = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement).entries()) as any;
          const ok = await act("plans", { ...f, year, estimated_value: Number(f.estimated_value || 0) }, "Plan line added.");
          if (ok) setOpen(false);
        }}>
          <Field name="title" label="Title" required />
          <div className="grid grid-cols-2 gap-3">
            <Field name="department" label="Department" />
            <Field name="funding_source" label="Funding source" />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field name="estimated_value" label="Estimated value (LKR)" type="number" />
            <label className="block">
              <span className="mb-1 block text-[12px] font-medium text-ink-600">Method</span>
              <select name="procurement_method" className="h-[38px] w-full rounded-[8px] border border-ink-300 px-2.5 text-[13px]">
                {METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
              </select>
            </label>
          </div>
          <Field name="planned_tender_date" label="Planned tender date" type="date" />
        </form>
      </Modal>

      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}

function Field({ name, label, ...rest }: { name: string; label: string } & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className="block">
      <span className="mb-1 block text-[12px] font-medium text-ink-600">{label}</span>
      <input name={name} {...rest} className="h-[38px] w-full rounded-[8px] border border-ink-300 px-2.5 text-[13px] outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
    </label>
  );
}
