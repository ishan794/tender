# TenderHub — Remediation Checklist

**Re-verified against the working tree on 2026-09-04**, running the actual built
application. A prior remediation pass had genuinely fixed the Next.js/BFF
security layer but had **not** touched the deployment/infra layer, while marking
it "fixed" — those rows are corrected here. Every status below reflects what was
observed this session, not what a document claimed.

### Legend
- ✅ **verified** — fixed and confirmed this session with pasted evidence (build + real HTTP)
- 🔧 **fixed (written)** — corrected this pass in code/config; validated where the runtime exists here, otherwise build-validated in CI (no Docker/nginx/PHP/MySQL in this environment)
- 🟡 **reviewed** — PHP-side code read and looks correct, but never executed here (no PHP); per BUILD_RULES Rule 10 this is "written, not runtime-verified"
- ⛔ **blocked** — needs infrastructure or an irreversible/authorised action outside this environment

### Environment boundary (what could and could not be run here)
Available: Node 26, sqlite3, git, a real production Next.js build + server.
**Not available: PHP, Composer, MySQL, Docker.** Consequences:
- The BFF **fail-closed** path is fully testable — with no PHP, "API unreachable" is the live condition, so 502/503-not-fabrication is exactly what gets exercised.
- Authorisation/RBAC is fully testable — the BFF verifies tokens with `AUTH_JWT_SECRET`, which is controlled in the test env, so correctly-signed tokens exercise the real gate.
- The PHP happy-paths, Docker image builds, `compose config`, nginx routing, MySQL transactions/restore, and VPS deploy are **not** runnable here and are labelled accordingly.

---

## Critical (P0)

| ID | Area | Status | What was verified / done |
|----|------|--------|--------------------------|
| CR-01 | Fake admin login | ✅ verified | Fallback deleted (prior pass). `POST /api/auth/login {email:"attacker-admin@…"}` with API down → **503**, no `Set-Cookie`. |
| CR-02 | Forgeable `th_sess` | ✅ verified | Identity now from HS256-verified JWT (`lib/jwt.ts`, `lib/session.ts`). Forged base64 `th_sess` → `/console` **redirects**. alg:none / wrong-secret / expired / junk tokens all rejected. |
| CR-03 | Fake `/login` cookie | ✅ verified | `tenderhub_auth=authenticated` no longer honoured → `/dashboard` **redirects**. `/login` calls the real BFF; reset calls `/api/auth/forgot-password`. |
| CR-04 | Privileged SQL fallback | ✅ verified | `workspace-mutations.ts` deleted; token verified before forward. Junk token → **401**; valid staff token, API down → **503** (was 500 — I moved `apiBase()` inside the try so an unset `API_BASE` also fails closed). |
| CR-05 | Public cache on auth | ✅ verified | Login response `Cache-Control: private, no-store`; every `/api/*` response `private, no-store` + `Vary: Cookie`. |
| CR-06 | Frontend DB / false "empty" | ✅ verified | `db.ts` / `mock-data.ts` deleted; unreachable API → **503**, never a fabricated empty list. |
| CR-07 | **nginx exposes PHP / shadows BFF** | 🔧 fixed (written) | **Was NOT fixed** — nginx still routed `/api/`→PHP. Rewrote topology: public `:443`→Next.js only; PHP reachable solely via an **unpublished** internal `:8080` FastCGI gateway (`API_BASE=http://nginx:8080`). Needs a real `nginx -t` / `compose up` to runtime-confirm. |

## High (P1)

| ID | Area | Status | What was verified / done |
|----|------|--------|--------------------------|
| HI-01 | API image has no deps | 🔧 fixed (written) | **Was NOT fixed.** Rewrote `apps/api/Dockerfile` with a `composer:2` stage (`composer install --no-dev --optimize-autoloader`); added `apps/api/.dockerignore` (excludes `.env`, `vendor/`, `writable/`, `*.sqlite`). Build-verified in CI (no Docker here). |
| HI-02 | No secrets/API_BASE to containers | 🔧 fixed (written) | **Was NOT fixed.** compose now passes `API_BASE`, `AUTH_JWT_SECRET`, `SESSION_SECRET` to web and `auth.jwtSecret`/`files.signingKey` to api. `lib/api.ts` already throws in prod if `API_BASE` unset. |
| HI-03 | Committed default secrets | 🔧 fixed (written) | **Was NOT fixed.** Removed every `${VAR:-default}`; compose now uses `${VAR:?}` (refuses to boot unset). `.env.production.example` → `__SET_ME__`. `backup-db.sh` no longer defaults the password and uses `--defaults-extra-file`. **Rotate all previously-committed secrets before deploy (see below).** |
| HI-04 | CI can't fail / fake test | 🔧 fixed + ✅ | **Was NOT fixed** (CI still called the deleted `verify-all-phases.mjs`). Wrote `web/scripts/security-smoke.mjs` — real HTTP, 26 assertions, **exits non-zero on failure** (proved: broke one assertion → exit 1). Rewrote CI: lint(non-block)+typecheck+build+smoke as hard gates, a PHP phpunit job, honest docker/compose validation, and an opt-in deploy job. |
| HI-05 | DB with hashes in git | 🔧 + ⛔ | Both `*.sqlite` are `git rm --cached`'d and gitignored (prior pass). **History purge with `git filter-repo` remains a manual pre-handover step** (destructive; not run here). |
| HI-06 | Fabricated PDFs | ✅ verified | Fallback deleted; files route returns **503** (body is JSON problem, not `%PDF`). Junk/no token → **401**. Encoded traversal → **400**. Sandbox CSP + nosniff set. |
| HI-07 | Fake statistics | ✅ verified | `/api/stats`, `/api/tenders`, `/api/categories` deleted — routes now **404**. |
| HI-08 | Missing gate / quota | ✅ verified | `middleware.ts` verifies signed token, enforces per-portal group (bidder/company→`/console` **403**), server-side free-view quota. |
| HI-09 | No CSP/HSTS | ✅ + 🔧 | App sets CSP (report-only until `CSP_ENFORCE=1`) + HSTS (prior pass, verified in headers). nginx HSTS + dropped `X-XSS-Protection` added this pass (written). |
| HI-10 | JSON-LD XSS | ✅ verified | `notice-detail.tsx` escapes `<`,`>`,`&`,U+2028/9 before injection; build clean. |
| HI-11 | Fake 200 on unknown action | ✅ verified | Unknown workspace action → **503** (fallback removed), never a fabricated success. |
| HI-12 | Double navbar/footer | ✅ verified | Chrome moved to `(public)/layout.tsx`; portals render one shell. |

## Medium / Low (selected)

| ID | Area | Status | What was verified / done |
|----|------|--------|--------------------------|
| ME-01 | 950 ms splash | ✅ verified | `WebLoader` deleted. |
| ME-02 | robots/sitemap/metadata | ✅ verified | `/robots.txt` **200** (disallows `/api`, portals), `/sitemap.xml` **200** (dynamic, graceful when API down). |
| ME-03 | `/tenders-sri-lanka` dup | ✅ verified | Canonical metadata added. |
| ME-04 | Headings / labels | ✅ verified | Homepage now `h1→h2→h3` (no skip); search input labelled; watchlist icon buttons given `aria-label` this pass. |
| ME-05 | **Touch targets / contrast** | 🔧 fixed | **Was NOT fixed.** Language buttons measured 18–25 px at 375 px (Tamil failed WCAG 2.5.8). Root cause found + fixed: an **unlayered `* { min-width: 0 }`** in `globals.css` was defeating every `min-w-*` utility app-wide; moved it into `@layer base`. Buttons now **36×36** at 375 px, still compact on desktop. |
| ME-06 | Dead API client | ✅ verified | `api-client.ts`, `auth.ts`, codemod scripts, placeholder SVGs deleted. |
| ME-07 | No error boundaries | ✅ verified | `error.tsx`, `global-error.tsx`, `not-found.tsx`, `loading.tsx`, `/forbidden` present; `/does-not-exist` → **404**. |
| ME-08 | Drawer a11y | ✅ (code) | Navbar drawer has `role="dialog"`, `aria-modal`, Escape handler, focus trap, focus restore. Verified by source review (runtime click probe was flaky in headless). |
| ME-15 | Web image standalone | 🔧 fixed | `output: standalone` set (prior). Web Dockerfile **was broken** for it (copied node_modules, ran `next start`, wrong monorepo paths). Rewrote to copy `standalone/web/server.js` + static/public under `web/`; **verified the standalone server serves 200 + CSS locally**. |
| LO-01/03/04/06 | compose/nginx hygiene | 🔧 fixed | Removed obsolete `version:`; nginx proxy timeouts; web `/api/health` endpoint + compose/Docker health checks. |

## Procurement integrity (PHP — reviewed, not executed here)

| Item | Status | Note |
|------|--------|------|
| Sealed bids excluded pre-opening | 🟡 reviewed | `SubmissionModel::forProcurement($id,$opened)` selects only `id,reference,size_bytes,status,received_at` when not opened — bidder/price/security columns are **never selected**, not filtered after. Correct at query level (Rule 4). |
| Dual-control opening | 🟡 reviewed | `OpeningController` records `opened_by_a`/`opened_by_b`, rejects same officer. Correct on review. |
| Payment + org upgrade atomic | 🟡 reviewed | `PaymentController`/`CheckoutController`/`RefundController` use `transBegin()/transComplete()`. Correct on review. |
| These need a running PHP API + phpunit (the CI `api` job) to move from 🟡 to ✅. | | |

## Blocked by environment / requires your action

- ⛔ **Rotate secrets** previously committed (DB password, `JWT_SECRET`, `FILES_SIGNING_KEY`, `INGEST_SECRET_KEY`) before any deploy — assume public.
- ⛔ **git history purge** of the two `*.sqlite` blobs (`git filter-repo`) before handover — destructive, not run here.
- ⛔ **Docker image builds / `compose config` / nginx routing** — no Docker here; the CI `docker` job validates them.
- ⛔ **MySQL migration + backup/restore rehearsal**, **VPS deploy + rollback** — no MySQL/Docker/host; the opt-in CI `deploy` job scaffolds it.
- ⛔ **PHP happy-path + phpunit** — no PHP here; the CI `api` job runs it.

## Files changed this pass
`web/src/app/api/workspace/[...path]/route.ts` (503-hardening) · `web/src/app/api/health/route.ts` (new) ·
`web/scripts/security-smoke.mjs` (new, real gate) · `web/package.json` (scripts) ·
`nginx/tenderhub.conf` · `apps/api/Dockerfile` · `apps/api/.dockerignore` (new) · `web/Dockerfile` · `web/.dockerignore` (new) ·
`docker-compose.prod.yml` · `.env.production.example` · `scripts/backup-db.sh` · `.github/workflows/deploy.yml` ·
`web/src/components/layout/Navbar.tsx` · `web/src/app/(public)/page.tsx` · `web/src/app/globals.css`
