"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { DataTable, type Column } from "@/components/ds/data-table";
import { Badge } from "@/components/ds/primitives";
import { Button } from "@/components/ds/controls";
import { Modal, Toast } from "@/components/ds/overlay";
import { lkr, date } from "@/lib/format";

const TONE: Record<string, "neutral" | "brand" | "ok" | "warn" | "bad"> = {
  draft: "neutral", active: "brand", suspended: "warn", completed: "ok", closed: "neutral", terminated: "bad",
};

export function ContractsTable({ rows }: { rows: any[] }) {
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
    { key: "contract_no", header: "Contract", sortable: true, sortValue: (r) => r.contract_no,
      cell: (r) => <span className="font-medium text-ink-900">{r.contract_no}</span>,
      meta: (r) => <>{r.title}{r.supplier_name ? ` · ${r.supplier_name}` : ""}</> },
    { key: "value", header: "Value", width: "140px", align: "right", sortable: true, sortValue: (r) => Number(r.value),
      cell: (r) => <span className="font-mono text-[13px]">{lkr(Number(r.value))}</span>,
      meta: (r) => r.end_date ? `ends ${date(r.end_date)}` : "" },
    { key: "status", header: "Status", width: "110px", sortable: true, sortValue: (r) => r.status,
      cell: (r) => <Badge tone={TONE[r.status] ?? "neutral"}>{r.status}</Badge> },
    { key: "actions", header: "", width: "200px", align: "right",
      cell: (r) => (
        <span className="flex justify-end gap-1.5">
          {r.status === "draft" ? <Button size="sm" onClick={() => act(`contracts/${r.id}/activate`, {}, "Contract activated.")}>Activate</Button> : null}
          {r.status === "active" ? <Button size="sm" variant="secondary" onClick={() => act(`contracts/${r.id}/transition`, { status: "completed" }, "Marked completed.")}>Complete</Button> : null}
          {r.status === "completed" ? <Button size="sm" variant="secondary" onClick={() => act(`contracts/${r.id}/transition`, { status: "closed" }, "Contract closed.")}>Close</Button> : null}
        </span>
      ) },
  ];

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button onClick={() => setOpen(true)}>Create from awarded tender</Button>
      </div>
      <DataTable rows={rows} columns={cols} searchKeys={(r) => `${r.contract_no} ${r.title} ${r.supplier_name ?? ""}`}
        filters={[{ key: "status", label: "Status", options: ["draft", "active", "completed", "closed"].map((s) => ({ value: s, label: s, n: rows.filter((r) => r.status === s).length })), match: (r, s) => s.includes(r.status) }]}
        empty={{ title: "No contracts yet", help: "Create a contract from an awarded tender to track delivery." }} />

      <Modal open={open} onClose={() => setOpen(false)} title="Create contract from an awarded tender" width={520}
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button><Button form="ct" type="submit">Create</Button></>}>
        <form id="ct" className="space-y-3.5" onSubmit={async (e) => {
          e.preventDefault();
          const f = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement).entries()) as any;
          const ok = await act("contracts", { procurement_id: Number(f.procurement_id), start_date: f.start_date || undefined, end_date: f.end_date || undefined, performance_security: f.performance_security ? Number(f.performance_security) : undefined, retention_pct: f.retention_pct ? Number(f.retention_pct) : undefined }, "Contract created.");
          if (ok) setOpen(false);
        }}>
          <label className="block"><span className="mb-1 block text-[12px] font-medium text-ink-600">Awarded tender id</span>
            <input name="procurement_id" type="number" required className="h-[38px] w-full rounded-[8px] border border-ink-300 px-2.5 text-[13px]" /></label>
          <div className="grid grid-cols-2 gap-3">
            <label className="block"><span className="mb-1 block text-[12px] font-medium text-ink-600">Start date</span><input name="start_date" type="date" className="h-[38px] w-full rounded-[8px] border border-ink-300 px-2.5 text-[13px]" /></label>
            <label className="block"><span className="mb-1 block text-[12px] font-medium text-ink-600">End date</span><input name="end_date" type="date" className="h-[38px] w-full rounded-[8px] border border-ink-300 px-2.5 text-[13px]" /></label>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <label className="block"><span className="mb-1 block text-[12px] font-medium text-ink-600">Performance security</span><input name="performance_security" type="number" className="h-[38px] w-full rounded-[8px] border border-ink-300 px-2.5 text-[13px]" /></label>
            <label className="block"><span className="mb-1 block text-[12px] font-medium text-ink-600">Retention %</span><input name="retention_pct" type="number" step="0.1" className="h-[38px] w-full rounded-[8px] border border-ink-300 px-2.5 text-[13px]" /></label>
          </div>
        </form>
      </Modal>
      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}
