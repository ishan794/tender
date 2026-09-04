import type { Metadata } from "next";
import HomePage from "../page";

/**
 * This URL is a deliberate SEO landing target for the platform's primary
 * keyword and shares the homepage's catalogue UI. Because the content overlaps
 * with `/`, it declares a self-canonical and its own title/description so search
 * engines treat it as an intentional destination rather than accidental
 * duplicate content splitting authority across two URLs.
 */
export const metadata: Metadata = {
  title: "Tenders in Sri Lanka — Live Government & Corporate Notices | TenderHub",
  description:
    "Browse live tenders in Sri Lanka: government gazette notices, ministry RFPs and corporate procurement across all 9 provinces, updated daily from official sources.",
  alternates: { canonical: "/tenders-sri-lanka" },
  openGraph: {
    title: "Tenders in Sri Lanka — Live Government & Corporate Notices",
    description:
      "Live tenders across all 9 provinces of Sri Lanka, updated daily from official gazette and ministry sources.",
    url: "/tenders-sri-lanka",
    type: "website",
  },
};

export default function TendersSriLankaPage() {
  return <HomePage />;
}
