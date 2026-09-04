/**
 * TenderHub BFF security smoke test — a gate that can actually fail.
 *
 * This replaces the old verify-all-phases.mjs, which imported an internal mock
 * function, asserted hardcoded `true`, made zero HTTP requests, and had no
 * `process.exit(1)` — so it printed "100% PASSED" while every auth bypass was
 * live. This script makes real HTTP requests against a real built server and
 * exits non-zero the moment any invariant is violated.
 *
 * It runs WITHOUT the PHP API: it points API_BASE at a dead port so the
 * fail-closed path (unreachable upstream -> 503, never fabricated data) is the
 * one under test, and it mints its own HS256 tokens with a throwaway secret to
 * exercise the authorisation logic (signature, expiry, alg pinning, per-portal
 * group checks) end to end. Backend happy-paths that need CodeIgniter are NOT
 * covered here by design — they belong in the full-stack integration job.
 *
 * Usage:
 *   node scripts/security-smoke.mjs              # spawns `next start`, tests, tears down
 *   BASE_URL=http://host:port node scripts/...   # tests an already-running server
 *
 * Requires a production build to exist (`next build`) when spawning.
 */

import crypto from "node:crypto";
import { spawn } from "node:child_process";
import { setTimeout as sleep } from "node:timers/promises";

const SECRET = "smoke_test_jwt_secret_min_32_chars_long_xx";
const PORT = Number(process.env.SMOKE_PORT ?? 3210);
const OWN_SERVER = !process.env.BASE_URL;
const BASE = process.env.BASE_URL ?? `http://127.0.0.1:${PORT}`;

// ---------------------------------------------------------------------------
// Token minting (mirrors what CodeIgniter issues; signed with the test secret)
// ---------------------------------------------------------------------------
const b64u = (b) => Buffer.from(b).toString("base64url");

function mint(claims, { secret = SECRET, alg = "HS256" } = {}) {
  const header = b64u(JSON.stringify({ alg, typ: "JWT" }));
  const now = Math.floor(Date.now() / 1000);
  const payload = b64u(JSON.stringify({ iss: "tenderhub.lk", iat: now, nbf: now, exp: now + 900, ...claims }));
  if (alg === "none") return `${header}.${payload}.`;
  const sig = crypto.createHmac("sha256", secret).update(`${header}.${payload}`).digest("base64url");
  return `${header}.${payload}.${sig}`;
}

const T = {
  staff: mint({ sub: 1, org: 1, role: "admin", grp: "staff", st: "active", plan: "staff", nm: "Staff" }),
  company: mint({ sub: 7, org: 2, role: "officer", grp: "company", st: "active", plan: "publish", nm: "Officer" }),
  bidder: mint({ sub: 9, org: 3, role: "owner", grp: "bidder", st: "active", plan: "business", nm: "Bidder" }),
  expired: mint({ sub: 7, org: 2, role: "officer", grp: "company", st: "active", plan: "publish", nm: "X", exp: Math.floor(Date.now() / 1000) - 10 }),
  wrongSecret: mint({ sub: 7, org: 2, role: "officer", grp: "company", st: "active", plan: "publish", nm: "X" }, { secret: "a_different_secret_of_length_thirty_two_x" }),
  algNone: mint({ sub: 1, org: 1, role: "admin", grp: "staff", st: "active", plan: "staff", nm: "X" }, { alg: "none" }),
};
const forgedSess = Buffer.from(JSON.stringify({ user: { id: 99, role: "admin", group: "staff" }, org: { id: 1, plan: "enterprise" } })).toString("base64url");

// ---------------------------------------------------------------------------
// HTTP helper
// ---------------------------------------------------------------------------
async function req(method, path, { cookie, body } = {}) {
  const headers = {};
  if (cookie) headers.Cookie = cookie;
  if (body) headers["Content-Type"] = "application/json";
  const res = await fetch(`${BASE}${path}`, { method, headers, body, redirect: "manual" });
  const text = await res.text().catch(() => "");
  return { status: res.status, headers: res.headers, text };
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------
const results = [];
function check(name, pass, detail = "") {
  results.push({ name, pass, detail });
  process.stdout.write(`${pass ? "  ✓" : "  ✗ FAIL"}  ${name}${detail ? `  (${detail})` : ""}\n`);
}
const isRedirect = (s) => s === 307 || s === 308 || s === 302 || s === 303;

async function run() {
  process.stdout.write("\n== Authentication bypass (former CR-01/02/03) ==\n");
  let r = await req("POST", "/api/auth/login", { body: JSON.stringify({ email: "attacker-admin@evil.com", password: "x" }) });
  check("login with *admin* email, API down -> 503 not a session", r.status === 503, `got ${r.status}`);
  check("login response never has a Set-Cookie", !r.headers.get("set-cookie"), r.headers.get("set-cookie") ? "SET A COOKIE" : "");
  check("login Cache-Control is private/no-store, never public", /no-store/.test(r.headers.get("cache-control") || "") && !/public/.test(r.headers.get("cache-control") || ""), r.headers.get("cache-control") || "");

  r = await req("GET", "/dashboard", { cookie: "tenderhub_auth=authenticated" });
  check("legacy tenderhub_auth cookie -> redirect (no longer honoured)", isRedirect(r.status), `got ${r.status}`);

  r = await req("GET", "/console", { cookie: `th_sess=${forgedSess}` });
  check("forged base64 th_sess -> /console redirected", isRedirect(r.status), `got ${r.status}`);

  process.stdout.write("\n== JWT tampering ==\n");
  for (const [label, tok] of [["junk", "not.a.jwt"], ["alg:none", T.algNone], ["wrong-secret", T.wrongSecret], ["expired", T.expired]]) {
    r = await req("GET", "/console", { cookie: `th_at=${tok}` });
    check(`${label} token -> /console redirected`, isRedirect(r.status), `got ${r.status}`);
  }

  process.stdout.write("\n== Authorisation / RBAC (valid signed tokens) ==\n");
  r = await req("GET", "/console", { cookie: `th_at=${T.bidder}` });
  check("bidder -> /console (staff-only) -> 403", r.status === 403, `got ${r.status}`);
  r = await req("GET", "/console", { cookie: `th_at=${T.company}` });
  check("company -> /console (staff-only) -> 403", r.status === 403, `got ${r.status}`);
  r = await req("GET", "/workspace", { cookie: `th_at=${T.bidder}` });
  check("bidder -> /workspace (company-only) -> 403", r.status === 403, `got ${r.status}`);
  r = await req("GET", "/console", { cookie: `th_at=${T.staff}` });
  check("staff -> /console -> 200 (shell renders; data 503)", r.status === 200, `got ${r.status}`);
  r = await req("GET", "/workspace", { cookie: `th_at=${T.company}` });
  check("company -> /workspace -> 200", r.status === 200, `got ${r.status}`);

  process.stdout.write("\n== Privileged mutations (former CR-04) ==\n");
  r = await req("POST", "/api/admin/organisations/3/verify", { cookie: "th_at=junk", body: "{}" });
  check("admin mutation, junk token -> 401", r.status === 401, `got ${r.status}`);
  r = await req("POST", "/api/admin/organisations/3/verify", { body: "{}" });
  check("admin mutation, no cookie -> 401", r.status === 401, `got ${r.status}`);
  r = await req("POST", "/api/admin/organisations/3/verify", { cookie: `th_at=${T.staff}`, body: "{}" });
  check("admin mutation, valid staff token, API down -> 503", r.status === 503, `got ${r.status}`);
  r = await req("POST", "/api/workspace/authority/tenders/1/delete-everything", { cookie: `th_at=${T.staff}`, body: "{}" });
  check("unknown workspace action -> 503, never a fake 200", r.status === 503, `got ${r.status}`);

  process.stdout.write("\n== Documents (former HI-06) ==\n");
  r = await req("GET", "/api/files/documents/9999", { cookie: "th_at=junk" });
  check("files, junk token -> 401", r.status === 401, `got ${r.status}`);
  r = await req("GET", "/api/files/documents/9999", { cookie: `th_at=${T.staff}` });
  check("files, valid token, API down -> 503", r.status === 503, `got ${r.status}`);
  check("files 503 body is NOT a fabricated PDF", !r.text.startsWith("%PDF"), r.text.slice(0, 8));
  r = await req("GET", "/api/files/%2e%2e%2fsecret", { cookie: `th_at=${T.staff}` });
  check("encoded path traversal in files -> 400", r.status === 400, `got ${r.status}`);

  process.stdout.write("\n== SEO / error surfaces ==\n");
  for (const [path, want] of [["/robots.txt", 200], ["/sitemap.xml", 200], ["/api/health", 200], ["/does-not-exist-xyz", 404]]) {
    r = await req("GET", path);
    check(`${path} -> ${want}`, r.status === want, `got ${r.status}`);
  }

  const failed = results.filter((x) => !x.pass);
  process.stdout.write(`\n${"=".repeat(64)}\n`);
  process.stdout.write(`${results.length - failed.length}/${results.length} checks passed\n`);
  if (failed.length) {
    process.stdout.write(`\nFAILED:\n${failed.map((f) => `  - ${f.name} (${f.detail})`).join("\n")}\n`);
    return 1;
  }
  return 0;
}

// ---------------------------------------------------------------------------
// Orchestration: spawn a server unless BASE_URL was provided
// ---------------------------------------------------------------------------
async function waitForReady(timeoutMs = 30_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(`${BASE}/api/health`, { redirect: "manual" });
      if (res.status === 200) return true;
    } catch {
      /* not up yet */
    }
    await sleep(400);
  }
  return false;
}

let child = null;
async function main() {
  if (OWN_SERVER) {
    child = spawn("npx", ["next", "start", "-p", String(PORT)], {
      cwd: new URL("..", import.meta.url),
      shell: true,
      env: {
        ...process.env,
        AUTH_JWT_SECRET: SECRET,
        SESSION_SECRET: SECRET,
        API_BASE: "http://127.0.0.1:59999", // configured but dead -> fail-closed path
        NODE_ENV: "production",
      },
      stdio: ["ignore", "ignore", "inherit"],
    });
    const ready = await waitForReady();
    if (!ready) {
      process.stderr.write("Server did not become ready in time.\n");
      child.kill("SIGKILL");
      process.exit(1);
    }
  }

  let code = 1;
  try {
    code = await run();
  } catch (e) {
    process.stderr.write(`Smoke test threw: ${e?.stack ?? e}\n`);
    code = 1;
  } finally {
    if (child) child.kill("SIGKILL");
  }
  process.exit(code);
}

main();
