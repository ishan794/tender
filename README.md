# TenderHub — Sri Lanka Tender & Auction Platform

> **Build Architecture:** Revision 3.0 (As-Built & Verified)

---

## 🏛️ System Metadata & Tech Stack

| Layer | Technology | Version | Purpose |
|---|---|---|---|
| **Backend** | CodeIgniter | `4.7.4` (PHP 8.3) | Headless JSON API, 37 controllers, 6 filters, DocumentStore |
| **Frontend** | Next.js | `16.3.3` (React 19) | App Router, Server Components, BFF Proxies, Tailwind v4 |
| **Data (Dev)** | SQLite | `3` | `tenderhub.sqlite` (30 tables, Forge migrations) |
| **Data (Prod)** | MySQL | `8` | utf8mb4, InnoDB (identical Forge schema) |
| **Auth** | firebase/php-jwt | `6` | HS256 JWT (15-min access), refresh token family rotation |
| **Design System** | FleetOps Tokens | Tailwind v4 | Primitives, Controls, DataTable, Modals, AppShell |
| **Scope** | Built | `82 Endpoints` · `28 Tables` · `28 Pages` | Complete core lifecycle & portals |
| **Remaining** | Planned | `Ingestion Crawler` · `Card Gateway` · `Deploy` | Phase 8 forward roadmap |

---

## 📁 Repository Layout

```
TenderHub/
├── apps/
│   ├── api/                          # CodeIgniter 4.7.4 Headless JSON API
│   │   ├── app/
│   │   │   ├── Commands/             # documents:mirror command
│   │   │   ├── Config/               # Routes (80+), Filters, Plans, Database
│   │   │   ├── Controllers/Api/V1/   # Public, Auth, Member, Authority, Admin, Partner
│   │   │   ├── Database/             # 9 Migrations (Forge), 3 Seeders
│   │   │   ├── Entities/             # Notice entity
│   │   │   ├── Filters/              # auth-jwt, tenant, group, entitlement, throttle
│   │   │   ├── Libraries/            # Jwt, DocumentStore (SHA-256 content-addressed)
│   │   │   ├── Models/               # 8 Core Active Record Models
│   │   │   └── Transformers/         # NoticeTransformer (Server-side Paywall)
│   │   └── writable/
│   │       ├── documents/            # Blob store (aa/bb/<sha256>.pdf)
│   │       └── tenderhub.sqlite      # SQLite development database (30 tables)
│   └── web/                          # Next.js 16.3.3 App Router Frontend
│       ├── app/
│       │   ├── (public)/             # Landing, /tenders, /auctions, /awards, /pricing
│       │   ├── (auth)/               # /bidder/signin, /company/signin, /subscription
│       │   ├── (portal)/
│       │   │   ├── app/              # Bidder Portal (Feed, Alerts, Pipeline, Vault)
│       │   │   ├── workspace/        # Procurement Workspace (7 Stages, Sealed Bids)
│       │   │   └── console/          # Staff Console (Health, Ingestion, Orgs, Payments)
│       │   └── api/                  # 5 BFF Route Handlers (auth, workspace, admin, notices, files)
│       ├── components/
│       │   ├── ds/                   # Design System (primitives, controls, data-table, overlay, nav)
│       │   ├── catalog/              # Public catalogue & search composites
│       │   └── portal/               # 11 Portal panels & tables
│       ├── lib/                      # db, api, mock-data, workspace-mutations, format, urls
│       └── proxy.ts                  # Single gate middleware (role & 5-free-view quota)
├── BUILD_RULES.md                    # Core Rules 0 through 10 (Project Constitution)
├── MOBILE_RULES.md                   # Responsive Rules 0 through 14 (375px baseline)
└── docs/                             # Technical Blueprints & Architecture Specs
```

---

## 🚀 Running the System

### 1. Frontend (Next.js 16)
```bash
cd web
npm install
npm run dev
# Running on http://localhost:3000 and http://0.0.0.0:3000 (LAN/Mobile)
```

### 2. Backend (CodeIgniter 4 API)
```bash
cd apps/api
php spark serve --host 0.0.0.0 --port 8080
```

### 3. Verification Suite
```bash
cd web
npx tsx scripts/verify-all-phases.mjs
```
