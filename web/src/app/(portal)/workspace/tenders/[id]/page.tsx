import { notFound } from "next/navigation";
import { authed } from "@/lib/api";
import { readSession } from "@/lib/session";
import { TenderWorkspace } from "@/components/portal/tender-workspace";
import { PerTenderPanels } from "@/components/portal/per-tender-panels";
import type { Submission } from "@/lib/types";

export const metadata = { title: "Tender workspace" };

export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await authed(`/api/v1/authority/tenders/${id}`);
  if (res.status === 404) notFound();

  const proc = res.body?.data;
  const session = await readSession();

  const [subsRes, docsRes, clarRes, addRes, purchRes, awardRes, ledgerRes, complaintsRes, sigRes, tcoRes] = await Promise.all([
    authed(`/api/v1/authority/tenders/${id}/submissions`),
    authed(`/api/v1/authority/tenders/${id}/documents`),
    authed(`/api/v1/authority/tenders/${id}/clarifications`),
    authed(`/api/v1/authority/tenders/${id}/addenda`),
    authed(`/api/v1/authority/tenders/${id}/purchasers`),
    authed(`/api/v1/authority/tenders/${id}/award`),
    authed(`/api/v1/authority/tenders/${id}/ledger`),
    authed(`/api/v1/authority/tenders/${id}/complaints`),
    authed(`/api/v1/authority/tenders/${id}/signatures`),
    authed(`/api/v1/authority/tenders/${id}/tco`),
  ]);

  const opened = subsRes.body?.meta?.opened === true;
  const raw: any[] = subsRes.body?.data ?? [];

  /**
   * REDACTED ON THE SERVER, BEFORE THE CLIENT BOUNDARY.
   *
   * The Submissions tab once displayed "Sealed until opening" while the page
   * passed the full submission array to a client component. React serialises
   * client-component props into the streamed RSC payload, so every bidder name
   * and price sat in the page source behind a label saying they were sealed.
   *
   * The API already withholds those columns before the opening, so `raw` cannot
   * contain them — but we redact again here so that a future change to the API
   * cannot quietly reintroduce the leak through this component. Defence in
   * depth on the one thing that must never leak.
   */
  const submissions: Submission[] = opened
    ? raw
    : raw.map((s) => ({
        id: s.id, reference: s.reference, size_bytes: s.size_bytes,
        status: s.status, received_at: s.received_at,
      }));

  return (
    <>
      <TenderWorkspace
        proc={proc}
        me={session!.user.id}
        opened={opened}
        submissions={submissions}
        withheld={subsRes.body?.meta?.withheld ?? []}
        withheldReason={subsRes.body?.meta?.withheld_reason ?? ""}
        opensAt={subsRes.body?.meta?.opens_at ?? null}
        documents={docsRes.body?.data ?? []}
        clarifications={clarRes.body?.data ?? []}
        addenda={addRes.body?.data ?? []}
        purchasers={purchRes.body?.data ?? []}
        purchaseMeta={purchRes.body?.meta ?? {}}
        award={awardRes.body?.data ?? null}
        awardMeta={awardRes.body?.meta ?? {}}
        ledger={ledgerRes.body?.data ?? []}
        ledgerIntegrity={ledgerRes.body?.meta?.integrity ?? null}
        complaints={complaintsRes.body?.data ?? []}
      />
      <PerTenderPanels
        id={Number(id)}
        canSign={session!.user.role !== "viewer"}
        estimatedValue={Number(proc?.estimated_value ?? 0)}
        signatures={sigRes.body?.data ?? []}
        tco={(tcoRes.body?.data ?? [])[0] ?? null}
      />
    </>
  );
}
