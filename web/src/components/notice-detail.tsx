import Link from "next/link";
import { notFound } from "next/navigation";
import { apiFetch, token } from "@/lib/api";
import { readSession } from "@/lib/session";
import { lkr, bytes, dateTime, countdown, titleCase } from "@/lib/format";
import { Badge, Card, CardBody, CardHead, KeyValue, StatusBadge } from "@/components/ds/primitives";
import { LinkButton } from "@/components/ds/controls";
import { DocumentList } from "@/components/portal/document-list";
import type { Notice } from "@/lib/types";

const LOCK_COPY: Record<string, string> = {
  buyer: "Which organisation is issuing this",
  summary: "The full summary",
  description: "The full scope, qualification requirements and conditions",
  description_teaser: "The scope of works",
  documents: "The bidding documents, bill of quantities and specification",
  contact_officer: "The contact officer",
  contact_phone: "A direct telephone number",
  contact_email: "A direct e-mail address",
  source_url: "The original source notice",
  document_fee: "What the bidding documents cost",
  bid_security: "The bid security required",
  published_at: "When this was published",
};

export async function NoticeDetail({ slug, kind }: { slug: string; kind: "tender" | "auction" }) {
  const path = kind === "auction" ? "/api/v1/auctions" : "/api/v1/notices";
  const res = await apiFetch<Notice>(`${path}/${slug}`, { token: await token() });

  if (res.status === 404) notFound();
  if (!res.ok && res.status !== 200) {
    return <Card><CardBody>{res.body?.detail ?? "This notice could not be loaded."}</CardBody></Card>;
  }

  const n = res.body.data as Notice;
  const now = res.body.meta?.now ?? new Date().toISOString();
  const session = await readSession();
  const locked = n.locked ?? [];

  /**
   * JSON-LD. Gated fields are OMITTED ENTIRELY — the buyer name was once
   * withheld from the visible page but written into the structured data block:
   * visible in view-source in one keystroke, and served to crawlers while
   * hidden from users, which is cloaking.
   */
  const jsonLd: Record<string, any> = {
    "@context": "https://schema.org",
    "@type": "GovernmentService",
    name: n.title,
    identifier: n.reference,
    areaServed: n.district ?? "Sri Lanka",
    serviceType: n.category ?? undefined,
    ...(locked.includes("buyer") ? {} : n.buyer ? { provider: { "@type": "GovernmentOrganization", name: n.buyer } } : {}),
  };

  /**
   * JSON.stringify does NOT escape `<`, `>` or `&`, so a notice title
   * containing `</script>` — and titles come from the untrusted gazette
   * crawler — would close this block early and inject markup (stored XSS).
   * Escaping those code points as \uXXXX keeps the JSON byte-identical to a
   * parser while making the closing-tag sequence impossible to form.
   */
  const jsonLdSafe = JSON.stringify(jsonLd)
    .replace(/</g, "\\u003c")
    .replace(/>/g, "\\u003e")
    .replace(/&/g, "\\u0026")
    .replace(/\u2028/g, "\\u2028")
    .replace(/\u2029/g, "\\u2029");

  return (
    <div className="mx-auto max-w-[1100px] px-5 py-8">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdSafe }} />

      <nav className="mb-4 flex items-center gap-1.5 text-[12px] text-ink-500">
        <Link href="/" className="hover:text-brand-700">Home</Link><span className="text-ink-300">/</span>
        <Link href={kind === "auction" ? "/auctions" : "/tenders"} className="hover:text-brand-700">{kind === "auction" ? "Auctions" : "Tenders"}</Link>
        <span className="text-ink-300">/</span><span className="font-mono text-ink-400">{n.reference}</span>
      </nav>

      <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div className="space-y-5">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge status={n.status} />
              {n.is_native ? <Badge tone="brand">Published on TenderHub</Badge> : null}
              <Badge>{titleCase(n.sector)}</Badge>
              {n.title_si ? <Badge tone="neutral">සිංහල</Badge> : null}
              {n.title_ta ? <Badge tone="neutral">தமிழ்</Badge> : null}
              <span className="font-mono text-[11px] text-ink-400">{n.reference}</span>
            </div>
            <h1 className="mt-2.5 text-[26px] font-semibold leading-tight tracking-tight text-ink-900">{n.title}</h1>
            {n.title_si && n.title_si !== n.title ? (
              <p className="mt-1 text-[15px] font-medium text-ink-700">{n.title_si}</p>
            ) : null}
            {n.title_ta && n.title_ta !== n.title ? (
              <p className="mt-1 text-[15px] font-medium text-ink-700">{n.title_ta}</p>
            ) : null}
            {n.buyer ? <p className="mt-2 text-[14px] text-ink-600">{n.buyer}</p> : null}
          </div>

          <Card>
            <CardBody>
              <KeyValue items={[
                ["Category", n.category ?? "—"],
                ["District", n.district ?? "—"],
                ["Estimated value", <span key="v" className="font-mono">{lkr(n.estimated_value)}</span>],
                ["Closes", <span key="c" className="font-mono">{dateTime(n.closing_at)}</span>],
                ["Bids opened", <span key="o" className="font-mono">{dateTime(n.opening_at)}</span>],
                ["Time remaining", <span key="t" className="font-mono font-medium">{countdown(n.closing_at, now)}</span>],
              ]} />
            </CardBody>
          </Card>

          {n.auction ? (
            <Card>
              <CardHead title="The lot" sub={`${titleCase(n.auction.asset_class)} · ${titleCase(n.auction.method)}`} />
              <CardBody>
                <KeyValue items={[
                  ["Reserve", <span key="r" className="font-mono">{lkr(n.auction.reserve)}</span>],
                  ["Deposit", <span key="d" className="font-mono">{lkr(n.auction.deposit)} ({n.auction.deposit_pct}%)</span>],
                  ["Venue", n.auction.venue ?? "—"],
                  ["Auctioneer", n.auction.auctioneer ?? "—"],
                  ...(n.auction.result ? [["Result", <Badge key="res" tone={n.auction.result === "sold" ? "ok" : "neutral"}>{titleCase(n.auction.result)}</Badge>] as [string, any]] : []),
                ]} />
                <p className="mt-4 rounded-[8px] bg-warn-50 px-3 py-2 text-[12px] text-warn-600 ring-1 ring-inset ring-amber-200">
                  {n.auction.custody_note}
                </p>
              </CardBody>
            </Card>
          ) : null}

          {n.summary || n.summary_teaser ? (
            <Card>
              <CardHead title="Summary" />
              <CardBody><p className="whitespace-pre-line text-[14px] leading-relaxed text-ink-700">{n.summary ?? n.summary_teaser}</p></CardBody>
            </Card>
          ) : null}

          {n.description ? (
            <Card>
              <CardHead title="Scope and conditions" />
              <CardBody><p className="whitespace-pre-line text-[14px] leading-relaxed text-ink-700">{n.description}</p></CardBody>
            </Card>
          ) : n.description_teaser ? (
            <Card>
              <CardHead title="Scope and conditions" />
              <CardBody>
                <p className="text-[14px] leading-relaxed text-ink-700">{n.description_teaser}</p>
                <p className="mt-3 text-[13px] text-ink-400">The rest of the scope is available to subscribers.</p>
              </CardBody>
            </Card>
          ) : null}

          <Card>
            <CardHead title="Documents" sub={locked.includes("documents") ? `${n.documents_count} documents attached` : undefined} />
            {locked.includes("documents") ? (
              <CardBody>
                <div className="rounded-[8px] border border-dashed border-ink-300 bg-ink-50 px-4 py-6 text-center">
                  <p className="text-[14px] font-medium text-ink-800">
                    {n.documents_count} document{n.documents_count === 1 ? "" : "s"} behind the subscription
                  </p>
                  <p className="mx-auto mt-1.5 max-w-sm text-[13px] leading-relaxed text-ink-500">
                    The bidding document, bill of quantities and specification are what you need to actually price
                    this. Subscribers get them as mirrored files, on a link that expires in five minutes.
                  </p>
                  <LinkButton href="/subscription" className="mt-4">See the plans</LinkButton>
                </div>
              </CardBody>
            ) : (
              <DocumentList noticeId={n.id} documents={n.documents ?? []} />
            )}
          </Card>
        </div>

        <aside className="space-y-4 lg:sticky lg:top-20 lg:self-start">
          {locked.length ? (
            <Card className="border-brand-200 bg-brand-50/40">
              <CardHead title={session ? "Included with a subscription" : "Create a free account to see more"} />
              <CardBody>
                {/* Honest explanation in place of the withheld fields. The names
                    are all the payload carries — the values were never
                    serialised. */}
                <ul className="space-y-2">
                  {locked.filter((f) => LOCK_COPY[f]).map((f) => (
                    <li key={f} className="flex gap-2 text-[13px] leading-snug text-ink-600">
                      <span className="mt-0.5 text-ink-400">🔒</span>{LOCK_COPY[f]}
                    </li>
                  ))}
                </ul>
                <div className="mt-4 space-y-2">
                  {session ? (
                    <LinkButton href="/subscription" className="w-full">Subscribe — Rs. 24,000/yr</LinkButton>
                  ) : (
                    <>
                      <LinkButton href="/bidder/signup" className="w-full">Create a free account</LinkButton>
                      <p className="text-center text-[12px] text-ink-500">Five notice views, no card required.</p>
                    </>
                  )}
                </div>
              </CardBody>
            </Card>
          ) : (
            <Card>
              <CardHead title="Contact" />
              <CardBody>
                <KeyValue items={[
                  ["Officer", n.contact_officer ?? "—"],
                  ["Telephone", n.contact_phone ?? "—"],
                  ["E-mail", n.contact_email ?? "—"],
                  ["Document fee", <span key="f" className="font-mono">{lkr(n.document_fee)}</span>],
                  ["Bid security", <span key="s" className="font-mono">{lkr(n.bid_security)}</span>],
                ]} />
                {n.source_url ? (
                  <a href={n.source_url} target="_blank" rel="noopener noreferrer nofollow"
                     className="mt-4 block text-[13px] text-brand-600 hover:underline">View at source ↗</a>
                ) : null}
              </CardBody>
            </Card>
          )}

          <Card>
            <CardBody className="text-[12px] leading-relaxed text-ink-500">
              <p className="font-medium text-ink-700">About this deadline</p>
              <p className="mt-1.5">
                The countdown is computed from server time, not your browser clock, and a published closing date
                can only move by a numbered addendum — never by a silent edit.
              </p>
            </CardBody>
          </Card>
        </aside>
      </div>
    </div>
  );
}
