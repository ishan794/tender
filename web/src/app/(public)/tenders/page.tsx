import { Suspense } from "react";
import { Catalogue } from "@/components/catalog/catalogue";
import { Skeleton, Card } from "@/components/ds/primitives";

export const metadata = {
  title: "Sri Lanka Government & Commercial Tenders Catalogue",
  description: "Browse verified Sri Lanka public procurement notices, government gazette tenders, RFP invitations, and procurement opportunities.",
  alternates: { canonical: "/tenders" },
};

export default async function TendersPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const sp = await searchParams;
  return (
    <div className="mx-auto max-w-[1200px] px-5 py-8">
      <h1 className="text-[26px] font-semibold tracking-tight text-ink-900">Tenders</h1>
      <p className="mt-1 mb-6 max-w-2xl text-[13px] text-ink-500">
        Verified Sri Lanka government gazette notices, ministry procurements, state enterprise RFPs, and commercial tenders with authenticated bidding documents and timelines.
      </p>
      <Suspense fallback={<Card><div className="space-y-3 p-5">{[...Array(6)].map((_, i) => <Skeleton key={i} className="h-14" />)}</div></Card>}>
        <Catalogue kind="tender" sp={sp} />
      </Suspense>
    </div>
  );
}