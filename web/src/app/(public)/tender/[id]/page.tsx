import { redirect } from "next/navigation";

export default async function LegacyTenderPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  redirect(`/tenders/${id}`);
}