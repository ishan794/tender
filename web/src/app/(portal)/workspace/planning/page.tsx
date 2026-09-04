import { authed } from "@/lib/api";
import { PageHead } from "@/components/ds/app-shell";
import { Card, CardBody } from "@/components/ds/primitives";
import { PlanningTable } from "@/components/portal/planning-table";

export const metadata = { title: "Procurement planning" };

export default async function PlanningPage({ searchParams }: { searchParams: Promise<{ year?: string }> }) {
  const sp = await searchParams;
  const year = Number(sp.year) || new Date().getFullYear();
  const res = await authed(`/api/v1/authority/plans?year=${year}`);
  if (res.unreachable) return <><PageHead title="Procurement planning" /><Card><CardBody>The service is temporarily unavailable.</CardBody></Card></>;
  return (
    <>
      <PageHead title="Procurement planning" sub="The annual procurement plan: what you intend to buy, its approval, and plan-vs-actual against published tenders." />
      <PlanningTable rows={res.body?.data ?? []} summary={res.body?.meta?.summary ?? {}} year={year} />
    </>
  );
}
