"use client";
import { useState } from "react";
import { Card, CardHead, CardBody } from "@/components/ds/primitives";
import { Button } from "@/components/ds/controls";
import { Toast } from "@/components/ds/overlay";

export function PrivacyPanel() {
  const [data, setData] = useState<any>(null);
  const [toast, setToast] = useState<string | null>(null);
  async function exportData() {
    const res = await fetch("/api/workspace/account/privacy/export");
    const j = await res.json().catch(() => ({}));
    if (res.ok) setData(j.data); else setToast("Could not load your data.");
  }
  async function request(kind: string) {
    const res = await fetch("/api/workspace/account/privacy/requests", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ kind }) });
    const j = await res.json().catch(() => ({}));
    setToast(res.ok ? `${kind} request received.${j.data?.note ? " " + j.data.note : ""}` : "Request failed.");
  }
  return (
    <>
      <Card className="mb-5">
        <CardHead title="Your data" sub="Export the personal data we hold about you (PDPA right of access)." right={<Button size="sm" onClick={exportData}>Export my data</Button>} />
        {data ? <CardBody><pre className="overflow-x-auto rounded-[8px] bg-ink-50 p-3 text-[12px] text-ink-700">{JSON.stringify(data, null, 2)}</pre></CardBody> : null}
      </Card>
      <Card>
        <CardHead title="Requests" sub="Ask us to correct or delete your data. Deletion is reviewed against legal holds and retention obligations before any action." />
        <CardBody>
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => request("correction")}>Request correction</Button>
            <Button variant="secondary" onClick={() => request("deletion")}>Request deletion</Button>
          </div>
        </CardBody>
      </Card>
      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}
