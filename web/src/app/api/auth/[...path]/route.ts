import { NextRequest, NextResponse } from "next/server";
import { apiBase, apiUnavailable } from "@/lib/api";
import { AT, RT, PROF, VIEWS, encodeProfile } from "@/lib/session";
import { verifyAccessToken } from "@/lib/jwt";
import { hit, clientIp } from "@/lib/rate-limit";

/**
 * BFF proxy — auth.
 *
 * This is the only handler that mints cookies, because access tokens live in
 * httpOnly cookies and something server-side has to attach them. Being on our
 * own server is still not authorisation: the credentials go to CodeIgniter,
 * CodeIgniter decides, and we only translate its answer into cookies.
 *
 * It used to contain a "graceful standalone fallback" that, when the API was
 * unreachable, minted a staff-administrator session for any e-mail containing
 * the word "admin" — no account lookup, no password check. Because the API was
 * never reachable in the deployed topology, that fallback *was* the login
 * system. It is gone. An auth service we cannot reach produces 503 and no
 * session, which is the only safe answer.
 */

export const dynamic = "force-dynamic";

/** Endpoints that hand back a session, and therefore set cookies. */
const SESSION_SEGMENTS = new Set(["login", "register", "refresh", "otp/verify"]);

/** Per-endpoint limits. Login and reset are the brute-force surfaces. */
const LIMITS: Record<string, { limit: number; windowMs: number }> = {
  login: { limit: 10, windowMs: 60_000 },
  register: { limit: 5, windowMs: 60_000 },
  "forgot-password": { limit: 5, windowMs: 60_000 },
  "reset-password": { limit: 10, windowMs: 60_000 },
  "otp/request": { limit: 5, windowMs: 60_000 },
  "otp/verify": { limit: 10, windowMs: 60_000 },
  refresh: { limit: 30, windowMs: 60_000 },
};
const DEFAULT_LIMIT = { limit: 30, windowMs: 60_000 };

function problem(status: number, reason: string, detail: string, extra: Record<string, unknown> = {}) {
  return NextResponse.json(
    { status, reason, detail, ...extra },
    { status, headers: { "Content-Type": "application/problem+json", "Cache-Control": "private, no-store" } },
  );
}

export async function POST(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const { path } = await ctx.params;
  const segment = path.join("/");

  // ---- sign-out is local: clear cookies, then tell the API to revoke ------
  if (segment === "signout" || segment === "logout") {
    const refresh = req.cookies.get(RT)?.value;
    const res = NextResponse.json(
      { data: { signed_out: true }, meta: { now: new Date().toISOString() } },
      { headers: { "Cache-Control": "private, no-store" } },
    );
    for (const c of [AT, RT, PROF, VIEWS]) res.cookies.set(c, "", { path: "/", maxAge: 0 });

    // Best effort: revoke the refresh family server-side so a captured cookie
    // dies with the session rather than living out its 30 days.
    if (refresh) {
      try {
        await fetch(`${apiBase()}/api/v1/auth/logout`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ refresh_token: refresh }),
          cache: "no-store",
          signal: AbortSignal.timeout(3000),
        });
      } catch {
        // The cookies are already cleared; a failed revoke must not block
        // sign-out. It is logged rather than surfaced.
        console.error("[auth] refresh-token revoke failed during sign-out");
      }
    }
    return res;
  }

  // ---- rate limit --------------------------------------------------------
  const cfg = LIMITS[segment] ?? DEFAULT_LIMIT;
  const ip = clientIp(req);
  const body = await req.text();

  let parsed: Record<string, unknown> = {};
  try {
    parsed = JSON.parse(body);
  } catch {
    /* non-JSON bodies are forwarded as-is and validated upstream */
  }

  // Limit per IP and, separately, per account. One attacker rotating IPs
  // against a single account is throttled by the second key.
  const email = typeof parsed.email === "string" ? parsed.email.toLowerCase().slice(0, 200) : "";
  const gates = [hit(`${segment}:ip:${ip}`, cfg.limit, cfg.windowMs)];
  if (email) gates.push(hit(`${segment}:acct:${email}`, cfg.limit, cfg.windowMs));

  const blocked = gates.find((g) => !g.ok);
  if (blocked) {
    return problem(429, "too_many_requests", "Too many attempts. Try again shortly.", {
      retry_after: blocked.retryAfterSec,
    });
  }

  // ---- forward to the authoritative backend ------------------------------
  let upstream: Response;
  try {
    upstream = await fetch(`${apiBase()}/api/v1/auth/${segment}`, {
      method: "POST",
      headers: { "Content-Type": req.headers.get("content-type") ?? "application/json" },
      body,
      cache: "no-store",
      signal: AbortSignal.timeout(10_000),
    });
  } catch (e) {
    console.error(`[auth] upstream unreachable for ${segment}:`, (e as Error)?.message);
    return apiUnavailable("auth_unavailable", "Sign-in is temporarily unavailable. Please try again shortly.");
  }

  const text = await upstream.text();
  let json: any;
  try {
    json = JSON.parse(text);
  } catch {
    console.error(`[auth] upstream returned non-JSON for ${segment} (status ${upstream.status})`);
    return apiUnavailable("bad_gateway", "The authentication service returned an unexpected response.");
  }

  // Anything the API refuses is relayed verbatim. Its refusals are already
  // written to avoid account enumeration; rewriting them here would undo that.
  if (!upstream.ok) {
    return NextResponse.json(json, {
      status: upstream.status,
      headers: { "Content-Type": "application/problem+json", "Cache-Control": "private, no-store" },
    });
  }

  // Non-session endpoints (forgot-password, resend-verification, otp/request)
  // pass straight through. The client must not claim an e-mail was sent unless
  // this succeeded — which it now cannot, because failures return above.
  if (!SESSION_SEGMENTS.has(segment)) {
    return NextResponse.json(json, { headers: { "Cache-Control": "private, no-store" } });
  }

  const d = json?.data;
  if (!d?.access_token) {
    console.error(`[auth] ${segment} returned 2xx without an access token`);
    return apiUnavailable("bad_gateway", "The authentication service returned an incomplete response.");
  }

  // Verify the token we were handed before we set it. If the API signed with a
  // secret we do not share, everything downstream would silently read as
  // unauthenticated — better to fail here, loudly, at the source.
  const claims = await verifyAccessToken(d.access_token);
  if (!claims) {
    console.error(
      "[auth] access token from the API failed local verification — " +
        "AUTH_JWT_SECRET probably does not match the API's auth.jwtSecret",
    );
    return apiUnavailable("token_verification_failed", "Sign-in could not be completed. Please try again shortly.");
  }

  const res = NextResponse.json(
    { data: { user: d.user, org: d.org }, meta: { now: new Date().toISOString() } },
    { headers: { "Cache-Control": "private, no-store" } },
  );

  const base = {
    httpOnly: true,
    sameSite: "lax" as const,
    path: "/",
    secure: process.env.NODE_ENV === "production",
  };

  res.cookies.set(AT, d.access_token, { ...base, maxAge: d.expires_in ?? 900 });
  if (d.refresh_token) res.cookies.set(RT, d.refresh_token, { ...base, maxAge: 60 * 60 * 24 * 30 });

  // Display-only, HMAC-signed, and bound to this user id. Nothing in it is
  // trusted for an access decision — role, group, org and plan are read from
  // the token's claims on every request.
  res.cookies.set(
    PROF,
    await encodeProfile(claims.sub, {
      email: d.user?.email,
      org_name: d.org?.name,
      org_type: d.org?.type,
      renews_at: d.org?.renews_at ?? null,
      verify_state: d.org?.verify_state,
      free_views_used: d.user?.free_views_used ?? 0,
    }),
    { ...base, maxAge: 60 * 60 * 24 * 30 },
  );

  res.cookies.set(VIEWS, "", { path: "/", maxAge: 0 });

  return res;
}
