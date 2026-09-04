import { authed } from "@/lib/api";
import { PageHead } from "@/components/ds/app-shell";
import { KycReviewTable } from "@/components/portal/kyc-review-table";

export const metadata = { title: "Vendor verification" };
export default async function KycPage() {
  const res = await authed("/api/v1/admin/kyc");
  return (
    <>
      <PageHead title="Vendor verification" sub="KYC submissions awaiting review. Only staff can verify — an organisation can never verify itself." />
      <KycReviewTable rows={res.body?.data ?? []} />
    </>
  );
}
