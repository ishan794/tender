import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { verifyAccessToken } from "@/lib/jwt";

/**
 * The single gate.
 *
 * BUILD_RULES Rule 3 calls for one place every role and quota check passes
 * through, with no route reachable by a path that skips it. That gate did not
 * exist: the previous middleware guarded five marketing-side routes by testing
 * whether a cookie named `tenderhub_auth` was *present* — a cookie the login
 * page set from client-side JavaScript, so any visitor could set it too. The
 * portals (`/console`, `/workspace`, `/app`) were not listed at all and relied
 * on layout guards that read an unsigned, forgeable session cookie.
 *
 * This version verifies a real signed token, enforces group membership per
 * portal, and applies the free-view quota server-side.
 */

/** Portal prefixes and the account group each one requires. */
const PORTALS: Array<{ prefix: string; group: "staff" | "company" | "bidder"; signin: string }> = [
  { prefix: "/console", group: "staff", signin: "/company/signin" },
  { prefix: "/workspace", group: "company", signin: "/company/signin" },
  { prefix: "/app", group: "bidder", signin: "/bidder/signin" },
];

/** Authenticated-but-not-portal routes. Any verified session may enter. */
const AUTHENTICATED_ROUTES = ["/dashboard", "/favorites", "/settings", "/related-tenders", "/subscription"];

/** Notice detail pages count against the free-view quota for signed-out users. */
const METERED_PATTERNS = [/^\/tender\/[^/]+$/, /^\/auctions\/[^/]+$/];

const FREE_VIEWS = Number(process.env.FREE_VIEW_LIMIT ?? 5);
const VIEWS_COOKIE = "th_views";

function isMetered(pathname: string) {
  return METERED_PATTERNS.some((re) => re.test(pathname));
}

function securityHeaders(res: NextResponse, isHttps: boolean) {
  res.headers.set("X-Frame-Options", "SAMEORIGIN");
  res.headers.set("X-Content-Type-Options", "nosniff");
  res.headers.set("Referrer-Policy", "strict-origin-when-cross-origin");
  res.headers.set("Permissions-Policy", "camera=(), microphone=(), geolocation=(), payment=()");
  res.headers.set("X-DNS-Prefetch-Control", "on");
  // X-XSS-Protection is deliberately NOT set: it is deprecated, ignored by
  // current browsers, and its legacy filter could itself introduce bugs. CSP
  // below is the actual control.

  if (isHttps) {
    res.headers.set("Strict-Transport-Security", "max-age=31536000; includeSubDomains");
  }

  // Report-only first so a missed inline script surfaces as a report rather
  // than a blank page. Flip CSP_ENFORCE=1 once the reports come back clean.
  const csp = [
    "default-src 'self'",
    // Next.js injects inline bootstrap scripts; 'unsafe-inline' is required
    // until a nonce is threaded through the document.
    "script-src 'self' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
    "font-src 'self' https://fonts.gstatic.com data:",
    "img-src 'self' data: blob: https://images.unsplash.com",
    "connect-src 'self'",
    "frame-ancestors 'self'",
    "base-uri 'self'",
    "form-action 'self'",
    "object-src 'none'",
  ].join("; ");

  res.headers.set(
    process.env.CSP_ENFORCE === "1" ? "Content-Security-Policy" : "Content-Security-Policy-Report-Only",
    csp,
  );
  return res;
}

export async function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const isHttps =
    request.nextUrl.protocol === "https:" || request.headers.get("x-forwarded-proto") === "https";

  // Verified claims, or null. Never "assume valid because a cookie exists".
  const claims = await verifyAccessToken(request.cookies.get("th_at")?.value ?? "");

  // ---- portal routes: authenticate, then check group ----------------------
  const portal = PORTALS.find((p) => pathname === p.prefix || pathname.startsWith(`${p.prefix}/`));
  if (portal) {
    if (!claims) {
      const url = new URL(portal.signin, request.url);
      url.searchParams.set("redirect", pathname);
      return securityHeaders(NextResponse.redirect(url), isHttps);
    }
    if (claims.grp !== portal.group) {
      // Wrong role is 403, not an upsell. A bidder who lands on a workspace
      // URL does not need a bigger plan; they need a different account.
      const res = NextResponse.rewrite(new URL("/forbidden", request.url), { status: 403 });
      return securityHeaders(res, isHttps);
    }
    const res = NextResponse.next();
    res.headers.set("Cache-Control", "private, no-store, max-age=0, must-revalidate");
    res.headers.set("Vary", "Cookie");
    return securityHeaders(res, isHttps);
  }

  // ---- other authenticated routes ----------------------------------------
  if (AUTHENTICATED_ROUTES.some((r) => pathname === r || pathname.startsWith(`${r}/`))) {
    if (!claims) {
      const url = new URL("/bidder/signin", request.url);
      url.searchParams.set("redirect", pathname);
      return securityHeaders(NextResponse.redirect(url), isHttps);
    }
    const res = NextResponse.next();
    res.headers.set("Cache-Control", "private, no-store, max-age=0, must-revalidate");
    res.headers.set("Vary", "Cookie");
    return securityHeaders(res, isHttps);
  }

  // ---- API routes ---------------------------------------------------------
  if (pathname.startsWith("/api/")) {
    const res = NextResponse.next();
    // Authenticated responses are never publicly cacheable. The old default
    // put `public, s-maxage=60` on every /api/ path — including the login
    // response, which carries Set-Cookie. A shared cache could replay one
    // user's session cookies to the next visitor. Route handlers set their own
    // Cache-Control; this is the floor, not an override of a public opt-in.
    if (!res.headers.has("Cache-Control")) {
      res.headers.set("Cache-Control", "private, no-store");
    }
    res.headers.set("Vary", "Cookie");
    return securityHeaders(res, isHttps);
  }

  // ---- public routes, with the free-view quota ----------------------------
  const res = NextResponse.next();

  if (isMetered(pathname) && !claims) {
    const used = Number(request.cookies.get(VIEWS_COOKIE)?.value ?? "0");
    const count = Number.isFinite(used) && used > 0 ? used : 0;

    if (count >= FREE_VIEWS) {
      const url = new URL("/subscription", request.url);
      url.searchParams.set("reason", "free_views_exhausted");
      url.searchParams.set("redirect", pathname);
      return securityHeaders(NextResponse.redirect(url), isHttps);
    }

    // The counter is a convenience for the signed-out browser, not the record
    // of truth: a signed-in user's quota is enforced by the API against
    // users.free_views. Clearing this cookie only re-grants the anonymous
    // allowance, which is why gated fields are withheld server-side too.
    res.cookies.set(VIEWS_COOKIE, String(count + 1), {
      httpOnly: true,
      sameSite: "lax",
      path: "/",
      secure: process.env.NODE_ENV === "production",
      maxAge: 60 * 60 * 24 * 30,
    });
    res.headers.set("Cache-Control", "private, no-store");
    res.headers.set("Vary", "Cookie");
    return securityHeaders(res, isHttps);
  }

  return securityHeaders(res, isHttps);
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico|robots.txt|sitemap.xml).*)"],
};
