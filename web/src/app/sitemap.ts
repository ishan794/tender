import type { MetadataRoute } from "next";
import { apiFetch } from "@/lib/api";

const BASE = process.env.NEXT_PUBLIC_SITE_URL ?? "https://tenderhub.lk";

/** Public marketing + catalogue routes that always exist. */
const STATIC_PATHS: Array<{ path: string; priority: number; freq: MetadataRoute.Sitemap[number]["changeFrequency"] }> = [
  { path: "/", priority: 1.0, freq: "daily" },
  { path: "/tenders", priority: 0.9, freq: "daily" },
  { path: "/tenders-sri-lanka", priority: 0.9, freq: "daily" },
  { path: "/auctions", priority: 0.8, freq: "daily" },
  { path: "/awards", priority: 0.6, freq: "weekly" },
  { path: "/transparency", priority: 0.7, freq: "weekly" },
  { path: "/subscriber-pricing", priority: 0.7, freq: "monthly" },
  { path: "/how-it-works", priority: 0.5, freq: "monthly" },
  { path: "/about-us", priority: 0.4, freq: "monthly" },
  { path: "/contact-us", priority: 0.4, freq: "monthly" },
  { path: "/blog/essential-parts", priority: 0.3, freq: "monthly" },
];

/**
 * Dynamic sitemap. Static routes are always emitted; notice/auction detail
 * URLs are added best-effort from the API. If the API is unreachable the
 * sitemap still returns the static set rather than failing — a partial sitemap
 * is far better than a 500 that removes the site from the index entirely.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const now = new Date();
  const entries: MetadataRoute.Sitemap = STATIC_PATHS.map((p) => ({
    url: `${BASE}${p.path}`,
    lastModified: now,
    changeFrequency: p.freq,
    priority: p.priority,
  }));

  try {
    const res = await apiFetch<any[]>("/api/v1/notices?limit=1000");
    const rows: any[] = Array.isArray(res.body?.data) ? res.body.data : [];
    for (const n of rows) {
      if (!n?.slug) continue;
      const kind = n.kind === "auction" ? "auctions" : "tenders";
      entries.push({
        url: `${BASE}/${kind}/${encodeURIComponent(n.slug)}`,
        lastModified: n.published_at ? new Date(n.published_at) : now,
        changeFrequency: "daily",
        priority: 0.7,
      });
    }
  } catch {
    // API unreachable at build/request time — ship the static set only.
  }

  return entries;
}
