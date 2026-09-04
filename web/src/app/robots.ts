import type { MetadataRoute } from "next";

const BASE = process.env.NEXT_PUBLIC_SITE_URL ?? "https://tenderhub.lk";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      {
        userAgent: "*",
        allow: "/",
        // Authenticated and transactional areas must never be crawled.
        disallow: ["/api/", "/app", "/workspace", "/console", "/dashboard", "/settings", "/favorites", "/subscription", "/forbidden"],
      },
    ],
    sitemap: `${BASE}/sitemap.xml`,
    host: BASE,
  };
}
