# TenderHub — Production Hardening Baseline

**Recorded Date**: 2026-09-04  
**Audit Baseline**: Build Blueprint Rev 3.0 Compliance Audit (88% compliance, 6 P0 blockers)  
**Git Commit**: `18e235b` (branch `main`)  
**Git Status**: Working tree synced with `origin/main`

---

## 1. Runtime Environment Snapshot

| Component | Version | Details |
|---|---|---|
| **Operating System** | Windows 11 / Windows NT 10.0 | Powershell host environment |
| **PHP Runtime** | PHP 8.3.33 (cli) | NTS Visual C++ 2019 x64 (`C:\php\php.exe`) |
| **PHP Modules** | bcmath, curl, intl, mbstring, mysqli, pdo_mysql, pdo_sqlite, sqlite3, openssl, zip | All extensions verified active |
| **CodeIgniter** | 4.7.4 | `spark` CLI operational |
| **PHPUnit** | 10.5.64 | `apps/api/vendor/bin/phpunit` |
| **Node.js** | v26.7.0 | Local runtime |
| **npm** | 11.19.0 | Local package manager |
| **Next.js** | 16.3.3 | App Router, Turbopack support |
| **Database Driver** | SQLite3 (`database.default.DBDriver = SQLite3`) | File: `apps/api/writable/tenderhub.sqlite` |
| **Production Driver**| MySQLi (configured in `apps/api/app/Config/Database.php`) | Port 3306 |

---

## 2. Codebase Architecture Metrics

| Metric | Value | Reference |
|---|---|---|
| **CI4 Routes** | 140 | `php spark routes` |
| **Next.js Pages** | 49 | `web/src/app/**/page.tsx` |
| **Total DB Migrations Run** | 10 | `2026-01-01` through `2026-01-10` |
| **Core Tables Seeded** | Yes | `CatalogueSeeder`, `ProcurementSeeder`, `DatabaseSeeder` |
| **Foreign Keys Active** | 0 | PRAGMA foreign_keys inactive |
| **Security Smoke Tests** | 26 HTTP Assertions | `web/scripts/security-smoke.mjs` |

---

## 3. P0 Blocker Baseline Inventory

| ID | Issue | Location | Evidence / State |
|---|---|---|---|
| **P0-01** | Hardcoded Merchant Secret | `apps/api/app/Libraries/Payments/PaymentGatewayService.php:18, :69` | Fallback `'4Xy78z9Q0r1S2t3U4v5W6x'` active if env unset |
| **P0-02** | Hardcoded Ingest Secret | `apps/api/app/Controllers/Api/V1/Admin/IngestWebhookController.php:18` | Fallback `'tenderhub_ingest_secret_2026'` active if env unset |
| **P0-03** | Missing Database Migrations | 4 tables referenced in code | `orders`, `password_resets`, `email_verifications`, `debarred_suppliers` have no migration |
| **P0-04** | Checkout User Attribution | `apps/api/app/Controllers/Api/V1/Payments/CheckoutController.php:28` | Hardcoded fallback `$user = ['id' => 1, ...]` always fires |
| **P0-05** | Fabricated Homepage Stats | `web/src/app/(public)/page.tsx` | Hardcoded `"39,942"` and `MOCK_TENDERS` mock array imported |
| **P0-06** | Missing `/tenders` Route | `web/src/app/(public)/tenders/` | Directory does not exist; only `/auctions` exists |
| **P0-07** | Missing `/tenders/[slug]` Route | `web/src/app/(public)/tenders/[slug]/` | Directory does not exist; sitemap points to mock `/tender/[id]` |

---

## 4. Verification Gate Criteria

Gate 1 will not pass until all 7 items above have:
1. Production code implemented.
2. Automated regression tests executed and passing.
3. Runtime verification with persistent database state confirmed.
