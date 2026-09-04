import { authed } from "@/lib/api";
import { PageHead } from "@/components/ds/app-shell";
import { Card, CardBody } from "@/components/ds/primitives";
import { ContractsTable } from "@/components/portal/contracts-table";

export const metadata = { title: "Contracts" };

export default async function ContractsPage() {
  const res = await authed("/api/v1/authority/contracts");
  if (res.unreachable) return <><PageHead title="Contracts" /><Card><CardBody>The service is temporarily unavailable.</CardBody></Card></>;
  return (
    <>
      <PageHead title="Contracts" sub="The lifecycle after award — milestones, variations, invoices and closure." />
      <ContractsTable rows={res.body?.data ?? []} />
    </>
  );
}
