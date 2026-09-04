import { NextRequest, NextResponse } from "next/server";
import { apiBase, apiUnavailable } from "@/lib/api";
import { AT } from "@/lib/session";
import { verifyAccessToken } from "@/lib/jwt";

/**
 * BFF proxy — the company workspace, the bidder portal and the staff console.
 *
 * It forwards the CALLER'S OWN token so CodeIgniter decides authorisation.
 * Being on our own server is not authorisation. This comment is here because
 * it is the mistake this pattern invites, and the mistake that was made: the
 * previous version answered upstream failures from `workspace-mutations.ts`,
 * which ran privileged SQL with a hardcoded actor of user 1 and no role check
 * at all. Confirming a payment, verifying an organisation and countersigning a
 * bid opening were all reachable with any non-empty cookie value.
 *
 * Two things now stand in the way:
 *   1. The token is VERIFIED here before anything is forwarded. Presence of a
 *      cookie is not a credential.
 *   2. There is no fallback. An unreachable API produces 502/503.
 *
 * ONE DETAIL THAT MATTERS: the request body is forwarded as BYTES with the
 * caller's own Content-Type. Re-reading it as text and re-labelling it JSON
 * corrupts a multipart upload, because the boundary lives in that header — and
 * document upload goes through this proxy. Learned the hard way.
 */

export const dynamic = "force-dynamic";

function unauthenticated() {
  return NextResponse.json(
    { status: 401, reason: "unauthenticated", detail: "Sign in to continue." },
    {
      status: 401,
      headers: { "Content-Type": "application/problem+json", "Cache-Control": "private, no-store" },
    },
  );
}

async function forward(req: NextRequest, path: string[], method: string) {
  const token = req.cookies.get(AT)?.value;
  if (!token) return unauthenticated();

  // Verify signature and expiry locally before spending an upstream round trip
  // on a token we can already tell is invalid. CodeIgniter re-verifies it and
  // applies the real filter chain — this is a cheap gate, not the gate.
  const claims = await verifyAccessToken(token);
  if (!claims) return unauthenticated();

  // Reject path traversal in the proxied segments before they reach the API.
  if (path.some((p) => p === ".." || p === "." || p.includes("\\") || p.includes("\0"))) {
    return NextResponse.json(
      { status: 400, reason: "bad_path", detail: "Malformed request path." },
      { status: 400, headers: { "Content-Type": "application/problem+json", "Cache-Control": "private, no-store" } },
    );
  }

  const headers = new Headers();
  headers.set("Authorization", `Bearer ${token}`);
  headers.set("Accept", "application/json");

  const ct = req.headers.get("content-type");
  if (ct) headers.set("Content-Type", ct);

  const init: RequestInit = { method, headers, cache: "no-store", signal: AbortSignal.timeout(30_000) };
  if (method !== "GET" && method !== "HEAD") {
    init.body = Buffer.from(await req.arrayBuffer()); // bytes, not text
  }

  // apiBase() resolution is INSIDE the try: in production with API_BASE unset
  // it throws by design, and that must surface as a clean fail-closed 503 —
  // not an uncaught 500 — exactly as an unreachable upstream does.
  let upstream: Response;
  try {
    const url = `${apiBase()}/api/v1/${path.map(encodeURIComponent).join("/")}${req.nextUrl.search}`;
    upstream = await fetch(url, init);
  } catch (e) {
    console.error(`[bff] upstream unreachable: ${method} ${path.join("/")} —`, (e as Error)?.message);
    return apiUnavailable("api_unavailable", "The service is temporarily unavailable. Please try again shortly.");
  }

  const buf = await upstream.arrayBuffer();

  return new NextResponse(buf, {
    status: upstream.status,
    headers: {
      "Content-Type": upstream.headers.get("content-type") ?? "application/json",
      // Authenticated data is never publicly cacheable, and varies by session.
      "Cache-Control": "private, no-store",
      Vary: "Cookie",
    },
  });
}

export async function GET(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "GET");
}
export async function POST(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "POST");
}
export async function PUT(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "PUT");
}
export async function PATCH(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "PATCH");
}
export async function DELETE(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "DELETE");
}
