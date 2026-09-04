import { NoticeDetail } from "@/components/notice-detail";
import { apiFetch } from "@/lib/api";
import type { Metadata } from "next";

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const res = await apiFetch(`/api/v1/notices/${slug}`);
  const n = res.body?.data;
  if (!n) return { title: "Tender notice not found" };
  return {
    title: `${n.title} — ${n.reference}`,
    description: n.summary_teaser ?? `${n.reference} — closing ${n.closing_at}`,
    alternates: { canonical: `/tenders/${n.slug}` },
    openGraph: { title: n.title, description: n.summary_teaser ?? "", url: `/tenders/${n.slug}` },
  };
}

export default async function TenderDetailPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  return <NoticeDetail slug={slug} kind="tender" />;
}