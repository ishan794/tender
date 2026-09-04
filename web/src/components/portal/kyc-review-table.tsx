"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { DataTable, type Column } from "@/components/ds/data-table";
import { Badge } from "@/components/ds/primitives";
import { Button } from "@/components/ds/controls";
import { Modal, Toast } from "@/components/ds/overlay";
import { dateTime } from "@/lib/format";

export function KycReviewTable({ rows }: { rows: any[] }) {
  const router = useRouter();
  const [toast, setToast] = useState<string | null>(null);
  const [reject, setReject] = useState<null | any>(null);

  async function review(id: number, action: string, reason?: string) {
    const res = await fetch(`/api/workspace/admin/kyc/${id}/review`, {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action, reason }),
    });
    const j = await res.json().catch(() => ({}));
    setToast(res.ok ? `Marked ${action}d.` : (j.detail ?? "That could not be recorded."));
    if (res.ok) router.refresh();
  }

  const cols: Column<any>[] = [
    { key: "org", header: "Organisation", sortable: true, sortValue: (r) => r.org_name,
      cell: (r) => <span className="font-medium text-ink-900">{r.org_name}</span>,
      meta: (r) => <>submitted {dateTime(r.submitted_at)}{r.categories ? ` · ${(JSON.parse(r.categories || "[]")).join(", ")}` : ""}</> },
    { key: "state", header: "Current", width: "120px",
      cell: (r) => <Badge tone={r.verify_state === "verified" ? "ok" : r.verify_state === "pending" ? "warn" : "neutral"}>{r.verify_state}</Badge> },
    { key: "actions", header: "", width: "180px", align: "right",
      cell: (r) => (
        <span className="flex justify-end gap-1.5">
          <Button size="sm" variant="secondary" onClick={() => setReject(r)}>Reject</Button>
          <Button size="sm" onClick={() => review(r.id, "approve")}>Approve</Button>
        </span>
      ) },
  ];

  return (
    <>
      <DataTable rows={rows} columns={cols} searchKeys={(r) => r.org_name}
        empty={{ title: "No submissions to review", help: "KYC submissions appear here when an organisation submits documents." }} />
      <Modal open={!!reject} onClose={() => setReject(null)} title="Reject KYC submission" width={480}
        footer={<><Button variant="secondary" onClick={() => setReject(null)}>Cancel</Button><Button form="rj" type="submit" variant="danger">Reject</Button></>}>
        <form id="rj" onSubmit={async (e) => { e.preventDefault(); const f = new FormData(e.currentTarget as HTMLFormElement); await review(reject.id, "reject", String(f.get("reason") || "")); setReject(null); }}>
          <label className="block"><span className="mb-1 block text-[12px] font-medium text-ink-600">Reason (the organisation sees this)</span>
            <textarea name="reason" rows={3} required className="w-full rounded-[8px] border border-ink-300 p-2.5 text-[13px]" /></label>
        </form>
      </Modal>
      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}
