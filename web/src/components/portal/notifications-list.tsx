"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { Card, CardBody, Badge, EmptyState } from "@/components/ds/primitives";
import { Toast } from "@/components/ds/overlay";
import { dateTime } from "@/lib/format";

export function NotificationsList({ rows, unread }: { rows: any[]; unread: number }) {
  const router = useRouter();
  const [toast, setToast] = useState<string | null>(null);
  async function read(id: number) {
    const res = await fetch(`/api/workspace/account/notifications/${id}/read`, { method: "POST", headers: { "Content-Type": "application/json" }, body: "{}" });
    if (res.ok) router.refresh(); else setToast("Could not update.");
  }
  return (
    <>
      <p className="mb-3 text-[13px] text-ink-500">{unread} unread</p>
      <Card><CardBody>
        {rows.length ? (
          <ol className="divide-y divide-ink-100">
            {rows.map((n) => (
              <li key={n.id} className={`flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0 ${n.read_at ? "opacity-60" : ""}`}>
                <span className="min-w-0">
                  <span className="flex items-center gap-2">
                    {!n.read_at ? <span className="h-2 w-2 rounded-full bg-brand-500" aria-label="unread" /> : null}
                    <span className="text-[13px] font-medium text-ink-900">{n.title}</span>
                  </span>
                  {n.body ? <p className="mt-0.5 text-[12px] text-ink-500">{n.body}</p> : null}
                  <span className="text-[11px] text-ink-400">{dateTime(n.created_at)}</span>
                </span>
                {!n.read_at ? <button onClick={() => read(n.id)} className="shrink-0 text-[12px] font-medium text-brand-600 hover:underline">Mark read</button> : <Badge tone="neutral">read</Badge>}
              </li>
            ))}
          </ol>
        ) : <EmptyState title="No notifications" help="You'll see matches, approvals and status changes here." />}
      </CardBody></Card>
      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}
