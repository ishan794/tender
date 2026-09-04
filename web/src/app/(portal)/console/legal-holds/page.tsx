import { authed } from "@/lib/api";
import { PageHead } from "@/components/ds/app-shell";
import { LegalHoldsPanel } from "@/components/portal/legal-holds-panel";

export const metadata = { title: "Legal holds" };
export default async function LegalHoldsPage() {
  const res = await authed("/api/v1/admin/legal-holds");
  return (
    <>
      <PageHead title="Legal holds" sub="While a hold is active, the held record cannot be deleted — evidence is preserved for disputes and audits." />
      <LegalHoldsPanel rows={res.body?.data ?? []} />
    </>
  );
}
