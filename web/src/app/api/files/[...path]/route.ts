import { NextRequest, NextResponse } from "next/server";
import { apiBase, apiUnavailable } from "@/lib/api";
import { AT } from "@/lib/session";
import { verifyAccessToken } from "@/lib/jwt";

/**
 * File downloads.
 *
 * The BFF requires a verified session before it will relay a signed link; the
 * signature, expiry and document-to-user binding are then re-checked by the
 * API on the way in. Both checks matter — this one stops an unauthenticated
 * request cheaply, and the API's is the one that actually authorises.
 *
 * The previous version, on upstream failure, synthesised a plausible-looking
 * "Democratic Socialist Republic of Sri Lanka" PDF for whatever document id
 * was asked for and returned 200. Every id was enumerable, every id succeeded,
 * and no user could tell a real bidding document from a fabricated one. There
 * is no fallback now: an unreachable document store returns 503.
 */

export const dynamic = "force-dynamic";

function unauthenticated() {
  return NextResponse.json(
    { status: 401, reason: "unauthenticated", detail: "Sign in to continue." },
    { status: 401, headers: { "Content-Type": "application/problem+json", "Cache-Control": "private, no-store" } },
  );
}

export async function GET(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const token = req.cookies.get(AT)?.value;
  if (!token) return unauthenticated();
  if (!(await verifyAccessToken(token))) return unauthenticated();

  const { path } = await ctx.params;

  // Traversal guard. Document ids are opaque segments; `..` never belongs.
  if (path.some((p) => p === ".." || p === "." || p.includes("\\") || p.includes("\0") || p.includes("/"))) {
    return NextResponse.json(
      { status: 400, reason: "bad_path", detail: "Malformed document reference." },
      { status: 400, headers: { "Content-Type": "application/problem+json", "Cache-Control": "private, no-store" } },
    );
  }

  let upstream: Response;
  try {
    upstream = await fetch(
      `${apiBase()}/api/v1/files/${path.map(encodeURIComponent).join("/")}${req.nextUrl.search}`,
      {
        headers: { Authorization: `Bearer ${token}` },
        cache: "no-store",
        signal: AbortSignal.timeout(30_000),
      },
    );
  } catch (e) {
    console.error("[files] upstream unreachable:", (e as Error)?.message);
    return apiUnavailable("api_unavailable", "Documents are temporarily unavailable. Please try again shortly.");
  }

  const buf = await upstream.arrayBuffer();
  const h = new Headers();
  for (const k of ["content-type", "content-disposition", "etag"]) {
    const v = upstream.headers.get(k);
    if (v) h.set(k, v);
  }
  h.set("Cache-Control", "private, no-store");
  h.set("Vary", "Cookie");
  // Documents are user-supplied content served back; never let a browser
  // re-interpret the type it was stored as.
  h.set("X-Content-Type-Options", "nosniff");
  h.set("Content-Security-Policy", "default-src 'none'; sandbox");

  return new NextResponse(buf, { status: upstream.status, headers: h });
}
