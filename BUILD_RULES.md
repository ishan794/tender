# TenderHub — Build Rules

These rules govern all architectural, implementation, testing, and status reporting tasks across the TenderHub codebase.

---

## Rule 0 — The blueprint is the only source of truth
The Rev 3.0 document (and the phase plan built from it) is the spec. Not the model's idea of what a tender platform usually looks like, not a package's example app, not "best practice" in the abstract.
- If a decision isn't in the blueprint, **stop and ask** — don't invent a plausible-sounding default and keep going.
- If the model's instinct disagrees with the blueprint (e.g. "normally you'd use a status string here"), the blueprint wins. If it thinks the blueprint is actually wrong, it says so explicitly and waits — it does not silently substitute its own pattern.
- Every generic SaaS/marketplace pattern is a suspect, not a default: multi-tenant orgs, roles, subscriptions — this system has specific, non-generic rules for all of them. Copying the generic version is the exact failure mode.

## Rule 1 — One module, fully, before the next
Do not scaffold all 28 tables, then all controllers, then all filters. For each module: migration → model → controller → filter/auth → manual verification against a running server, in that order, before starting the next module. A pile of half-wired controllers across ten modules is worse than three modules that actually work.

## Rule 2 — "Done" means verified against a running server, not written
A feature is not complete because the code compiles or the happy path returns 200 once. It's complete when the specific negative cases below are checked and refused correctly. If asked "is X done," the answer must be based on an actual request made against a running instance in this session — not a description of what the code should do.

## Rule 3 — Non-negotiable structural decisions
These are fixed. Do not "improve" or simplify them:
- **BFF pattern**: browser never talks to the API directly. Next.js route handlers forward the caller's own token; CodeIgniter is never publicly routable. `proxy.ts` is the single gate all role/quota checks pass through — no route reachable by any path (link, bookmark, typed URL, stale prefetch) that skips it.
- **Tokens live in httpOnly cookies**, never in JS-readable storage, never in localStorage/sessionStorage.
- **`notices` is one shared table** for tenders and auctions, `kind` fixed by route. Do not split into two tables.
- **`procurements.stage_idx` is an integer 0–6**, not a status string or enum of strings. Every "has this happened" check is `stage_idx >= n`.
- **Alert profiles match on slugs, never on auto-increment ids.**
- **Documents are content-addressed by SHA-256** (`aa/bb/<hash>.ext` fanout), never stored/served by a guessable static path.
- **The paywall is one transformer class** with a tier→field `RELEASE` map. A withheld field is never serialized — not filtered in the UI, not masked, not blurred. If a field shouldn't be visible, it must not leave the server in that response, in JSON-LD, or in any RSC client-component prop.
- **Plans are a config matrix, not a database table**, until there's a stated reason to move it (sales needs to edit it without a deploy).
- **The filter chain order is fixed**: `auth-jwt` → `tenant` → `group:<role>` → `entitlement:<key>`. Role check before plan check — a wrong-role user gets 403, not a 402 upsell for a plan they can't buy.

## Rule 4 — Security invariants (each must be independently verified, not assumed from role checks)
- Sealed bid data (bidder identity, price, security flag) is **never read out of the database** before opening — the query itself excludes those columns, it isn't filtered after fetching.
- Opening requires **two distinct officers** (`start` by one, `countersign` by a different one) — reject same-person with 403, reject countersign before published opening time with 409.
- An evaluator with no COI declaration on file, or a declared conflict, gets **no evaluation content at all** — not a greyed-out screen, a 403.
- Standstill period is **computed server-side** from org config — never accepted from client input.
- Self-approval above an org's threshold is refused **at the API** (403), never only hidden in the UI.
- Signed document links: minted on click (never embedded in rendered HTML), bound to document+user+expiry in one HMAC, constant-time compared, refused identically for tampered/expired/re-pointed/no-session (one message for all four — don't let error messages tell an attacker which guess was close).
- Deadlines (`closing_at`, opening time) are judged by **server time only**, never client/browser time.
- Identical error responses for unknown-account vs wrong-password, and for OTP whether-or-not-the-number-exists. No response shape may let someone enumerate accounts.
- Partner API keys are SHA-256 hashed at rest, never stored plaintext; webhook secrets shown exactly once at creation.

## Rule 5 — API conventions, fixed across all 82 endpoints
- Success: `{ data, meta }`, with `meta.now` (server time) on every payload.
- Failure: `application/problem+json` (RFC 9457), machine-readable reason, `upgrade_to` field when the failure is plan-related.
- Multi-select query params must accept repeated (`?district=1&district=2`), bracketed (`district[]=`), and comma-separated forms — PHP silently collapses repeated keys to the last value unless handled explicitly; check this specifically, don't assume the framework does it for you.
- Count queries and list queries must share the same filter conditions via one reusable constraint (closure/scope) applied to two independent query builders — never call a count method that resets or diverges from the list builder.

## Rule 6 — Explicit anti-pattern list (each of these is a documented real bug — check for it, don't just avoid it in theory)
- ❌ Filtering a locked field out in the frontend/component instead of never serializing it server-side.
- ❌ Passing a "sealed" data array as props to a client component and relying on a UI label to hide it — React serializes client-component props into the page source regardless of what's rendered.
- ❌ Writing a field into structured data (JSON-LD) that's withheld from the visible page.
- ❌ Comparing ids from different DB drivers with strict type equality (`SQLite` returns strings, `MySQL` returns ints) — cast explicitly before comparing.
- ❌ Accepting any non-empty value as a valid API key/token — actually verify against a stored hash.
- ❌ Bare date validation that accepts relative strings like "next tuesday" — pin an explicit format.
- ❌ Editing a published closing date directly instead of through an addendum — direct edits leave no record a date ever changed.
- ❌ A count query that resets/diverges from its paired list query's `WHERE`/`JOIN` clauses.
- ❌ Sending a proxied request body as re-parsed text instead of forwarding raw bytes with the original `Content-Type` — breaks multipart uploads (the boundary lives in that header).
- ❌ A service locator call for a service that isn't registered, allowed to fail silently instead of erroring loud — verify every `service()`/DI call resolves to something real, don't assume the framework will complain.
- ❌ Implementing backend logic directly inside the Next.js process (raw SQL queries in a frontend `lib/` file) instead of building the actual separate API service the blueprint specifies, and then calling that a "backend." If the file is named `mock-data.ts` or the function `getMockResponse`, it is a mock, whatever data source it queries.
- ❌ Auth that checks whether a token *string contains* a role/plan keyword (`token.includes("business")`) instead of verifying a real signed JWT (signature, expiry, claims). This is not authentication — any string with the right substring grants access.
- ❌ Blending a real query result with a hardcoded fallback via `Math.max(real, fakeNumber)` (or similar) so a plausible-looking number always shows even when the real count is low or zero. This silently fakes data under the appearance of a live query — the query result must be shown as-is, or explicitly marked empty/zero, never floored to a nicer-looking constant.
- ❌ "Verifying" a refusal rule (self-approval, same-officer opening, tampered link, early opening) by reading stored data and observing it looks consistent, instead of actually calling the endpoint with the violating request and pasting the real 403/409 response. Passive data inspection is not the same as an attempted negative test.

## Rule 7 — Honesty in status reporting
Never report a subsystem as done, working, or "built" without having made an actual request against a running instance and observed the actual response in this session. If something is implemented but not run/verified, say "written, not verified" — not "done." This mirrors the blueprint's own `built` / `partial` / `planned` labeling; keep using those three labels and don't round `partial` up to `built`.

## Rule 8 — When stuck, name the fork instead of picking silently
If there are two reasonable ways to implement something the blueprint doesn't fully specify, state both options and which one you're taking and why — in one line — rather than silently picking one. This is what makes a bad default catchable before it's built on top of.

## Rule 9 — No status claim without pasted raw output
A phase/subsystem/status table may **never** say "built" or "verified" unless the *actual* raw output that proves it is pasted directly beneath the claim — the real terminal output of the actual command, or the real HTTP response body, run in this session, against this codebase. Not a description of what the output would be. Not a summary. Not a number that sounds plausible ("366 Live / 98% Verified") with no query shown that produced it.

Concretely, for each claim:
- "Migrations ran" → paste the migration runner's actual console output.
- "Endpoint returns X" → paste the actual `curl`/HTTP response body and status code, not a description of it.
- "Paywall withholds field Y" → paste the actual response JSON and show the field is absent, or grep the actual served HTML and paste the (empty) match count.
- "Dual-control opening enforced" → paste the actual 403/409 response body from the actual second call.
- "Database has N rows" → paste the actual `SELECT count(*)` output.

If a claim can't be backed by pasted real output in that same turn, the correct status is **"written, not yet verified"** — not "built." A status table with no pasted evidence anywhere under it is a narrative, not a report, and must be treated as unverified regardless of how confident or detailed it reads. This rule exists because a model will produce a convincing status table from the spec's own wording without running anything, and that is indistinguishable from a real report unless raw output is demanded every time.

## Rule 10 — A subsystem that has never executed cannot be "built"
If the process that owns a piece of logic has never successfully run in this session (e.g. the PHP backend was never reachable on its port), then **every claim resting on that process's source code is "written," never "built" or "verified,"** no matter how complete the code looks on disk or how many files exist. File count and code review are not execution. Do not let an honest finding in one section ("PHP is not running") coexist with "built" labels in a later summary table for subsystems that only that process can serve — the summary must inherit the finding, not contradict it.

Two testing-methodology rules that follow from this:
- **A security/business-logic test must go through the real request path** — real HTTP call, real session/auth, real routing — not a direct in-process call to an internal function with a hand-built fake user object. Calling `someInternalFunction({id: 1})` from a Node script proves that function's logic is self-consistent; it proves nothing about whether the real running system enforces the rule, because it skips auth, routing, and every filter in between. If a test can't be run as an actual HTTP request, say so explicitly rather than substituting a function-level call and reporting it as equivalent.
- **A test that returns a description of what the code does ("refusal logic checks X") is not evidence.** Only the actual response body/status code, pasted verbatim, counts. If a described test and a pasted-output test are mixed in the same report, the described ones must be labeled unverified, not folded into the same "built" checkmark as the pasted ones.
- **A literal placeholder value inside real-looking infrastructure is still fake** — a cookie or token whose value is literally `mock_jwt_token_...` or similar is proof the auth is not implemented, not partial evidence toward it, however correctly the cookie flags (`HttpOnly`, `SameSite`) are set around it.
- **A requested test that gets silently substituted with a different, easier check is a gap, not a pass** — e.g. asked to verify a duplicate-submission is refused, running `SELECT count(*)` instead answers a different question and must be reported as "not tested," not folded into the same row as the tests that were actually run.
- **A file count spanning `vendor/`, framework core, or dependencies is not evidence of app code being written.** Count only the application's own directory (e.g. `apps/api/app/`) when using file counts as a completeness signal.
