# TenderHub Database Relationship & Portability Map

**Generated Date**: 2026-09-04  
**Audit Scope**: All 55 application & core tables (SQLite3 local & MySQL 8 production target)  
**Foreign Key Candidates**: 79  
**Orphan Records Detected**: 0 (Clean baseline verified)  

---

## 1. Executive Summary & Deletion Semantics Architecture

In public procurement, legal accountability, audit trails, and financial records cannot be casually deleted. Therefore, TenderHub uses a three-tier foreign key deletion policy:

1. **`RESTRICT` (Default for Core Entities)**: Applied to all financial, bidding, evaluation, contract, award, and audit ledger records. If a parent organisation, tender, user, or procurement has associated activity, database-level deletion is strictly blocked.
2. **`CASCADE` (Subordinate Lifecycle Records)**: Strictly reserved for disposable child records completely owned by a parent entity (e.g. `refresh_tokens` for users, `notice_documents` for notices, `auction_lots` for auction notices, `eval_scores` under a specific criterion).
3. **`SET NULL` (Optional Taxonomic & Hierarchical Links)**: Applied to self-referential links (such as category `parent_id` and notice `canonical_id`) and regional links (`districts`, `provinces`) where child records can stand alone if the parent category or region is reorganized.

---

## 2. Table-by-Table Relational Schema & Constraints

### `addenda`

- **Primary Key**: `id`
- **Columns Total**: 7
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `alert_profiles`

- **Primary Key**: `id`
- **Columns Total**: 14
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `user_id` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `api_keys`

- **Primary Key**: `id`
- **Columns Total**: 11
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `auction_lots`

- **Primary Key**: `id`
- **Columns Total**: 14
- **References (Outgoing Foreign Keys)**:
  - `notice_id` &rarr; `notices.id` (`ON DELETE CASCADE`) — *Strict lifecycle child owned by parent record; purged upon parent deletion.*
- **Referenced By**: None (Leaf Entity)

### `authorities`

- **Primary Key**: `id`
- **Columns Total**: 5
- **References**: None (Base/Root Entity)
- **Referenced By (Incoming Foreign Keys)**:
  - `notices.authority_id` (`ON DELETE SET NULL`)

### `awards`

- **Primary Key**: `id`
- **Columns Total**: 10
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `submission_id` &rarr; `submissions.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `supplier_org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By (Incoming Foreign Keys)**:
  - `contracts.award_id` (`ON DELETE RESTRICT`)
  - `ratings.award_id` (`ON DELETE RESTRICT`)

### `bid_seals`

- **Primary Key**: `id`
- **Columns Total**: 7
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `submission_id` &rarr; `submissions.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `bids`

- **Primary Key**: `id`
- **Columns Total**: 10
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `notice_id` &rarr; `notices.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `categories`

- **Primary Key**: `id`
- **Columns Total**: 4
- **References (Outgoing Foreign Keys)**:
  - `parent_id` &rarr; `categories.id` (`ON DELETE SET NULL`) — *Optional association; child entity remains valid even if parent link is removed.*
- **Referenced By (Incoming Foreign Keys)**:
  - `categories.parent_id` (`ON DELETE SET NULL`)
  - `notices.category_id` (`ON DELETE RESTRICT`)
  - `procurement_plans.category_id` (`ON DELETE RESTRICT`)

### `clarifications`

- **Primary Key**: `id`
- **Columns Total**: 8
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `coi_declarations`

- **Primary Key**: `id`
- **Columns Total**: 6
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `user_id` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `complaints`

- **Primary Key**: `id`
- **Columns Total**: 16
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `contract_invoices`

- **Primary Key**: `id`
- **Columns Total**: 8
- **References (Outgoing Foreign Keys)**:
  - `contract_id` &rarr; `contracts.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `contract_milestones`

- **Primary Key**: `id`
- **Columns Total**: 8
- **References (Outgoing Foreign Keys)**:
  - `contract_id` &rarr; `contracts.id` (`ON DELETE CASCADE`) — *Strict lifecycle child owned by parent record; purged upon parent deletion.*
- **Referenced By**: None (Leaf Entity)

### `contract_variations`

- **Primary Key**: `id`
- **Columns Total**: 8
- **References (Outgoing Foreign Keys)**:
  - `contract_id` &rarr; `contracts.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `created_by` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `contracts`

- **Primary Key**: `id`
- **Columns Total**: 17
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `award_id` &rarr; `awards.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `supplier_org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `created_by` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By (Incoming Foreign Keys)**:
  - `contract_invoices.contract_id` (`ON DELETE RESTRICT`)
  - `contract_milestones.contract_id` (`ON DELETE CASCADE`)
  - `contract_variations.contract_id` (`ON DELETE RESTRICT`)

### `data_requests`

- **Primary Key**: `id`
- **Columns Total**: 8
- **References (Outgoing Foreign Keys)**:
  - `user_id` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `debarred_suppliers`

- **Primary Key**: `id`
- **Columns Total**: 9
- **References**: None (Base/Root Entity)
- **Referenced By**: None (Leaf Entity)

### `districts`

- **Primary Key**: `id`
- **Columns Total**: 4
- **References (Outgoing Foreign Keys)**:
  - `province_id` &rarr; `provinces.id` (`ON DELETE SET NULL`) — *Optional association; child entity remains valid even if parent link is removed.*
- **Referenced By (Incoming Foreign Keys)**:
  - `notices.district_id` (`ON DELETE SET NULL`)
  - `organisations.district_id` (`ON DELETE SET NULL`)

### `doc_purchases`

- **Primary Key**: `id`
- **Columns Total**: 7
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `buyer_org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `document_assets`

- **Primary Key**: `id`
- **Columns Total**: 10
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `document_downloads`

- **Primary Key**: `id`
- **Columns Total**: 6
- **References (Outgoing Foreign Keys)**:
  - `notice_document_id` &rarr; `notice_documents.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `user_id` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `document_versions`

- **Primary Key**: `id`
- **Columns Total**: 9
- **References (Outgoing Foreign Keys)**:
  - `notice_document_id` &rarr; `notice_documents.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `email_verifications`

- **Primary Key**: `id`
- **Columns Total**: 5
- **References**: None (Base/Root Entity)
- **Referenced By**: None (Leaf Entity)

### `eval_criteria`

- **Primary Key**: `id`
- **Columns Total**: 7
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `eval_scores`

- **Primary Key**: `id`
- **Columns Total**: 10
- **References (Outgoing Foreign Keys)**:
  - `submission_id` &rarr; `submissions.id` (`ON DELETE CASCADE`) — *Strict lifecycle child owned by parent record; purged upon parent deletion.*
- **Referenced By**: None (Leaf Entity)

### `event_ledger`

- **Primary Key**: `id`
- **Columns Total**: 13
- **References (Outgoing Foreign Keys)**:
  - `actor_id` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `factories`

- **Primary Key**: `id`
- **Columns Total**: 9
- **References**: None (Base/Root Entity)
- **Referenced By**: None (Leaf Entity)

### `feed_sources`

- **Primary Key**: `id`
- **Columns Total**: 11
- **References**: None (Base/Root Entity)
- **Referenced By (Incoming Foreign Keys)**:
  - `notices.source_id` (`ON DELETE RESTRICT`)

### `invitations`

- **Primary Key**: `id`
- **Columns Total**: 9
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `kyc_submissions`

- **Primary Key**: `id`
- **Columns Total**: 11
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `legal_holds`

- **Primary Key**: `id`
- **Columns Total**: 8
- **References (Outgoing Foreign Keys)**:
  - `created_by` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `migrations`

- **Primary Key**: `id`
- **Columns Total**: 7
- **References**: None (Base/Root Entity)
- **Referenced By**: None (Leaf Entity)

### `notice_documents`

- **Primary Key**: `id`
- **Columns Total**: 14
- **References (Outgoing Foreign Keys)**:
  - `notice_id` &rarr; `notices.id` (`ON DELETE CASCADE`) — *Strict lifecycle child owned by parent record; purged upon parent deletion.*
- **Referenced By (Incoming Foreign Keys)**:
  - `document_downloads.notice_document_id` (`ON DELETE RESTRICT`)
  - `document_versions.notice_document_id` (`ON DELETE RESTRICT`)

### `notices`

- **Primary Key**: `id`
- **Columns Total**: 33
- **References (Outgoing Foreign Keys)**:
  - `authority_id` &rarr; `authorities.id` (`ON DELETE SET NULL`) — *Optional association; child entity remains valid even if parent link is removed.*
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `category_id` &rarr; `categories.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `district_id` &rarr; `districts.id` (`ON DELETE SET NULL`) — *Optional association; child entity remains valid even if parent link is removed.*
  - `source_id` &rarr; `feed_sources.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `canonical_id` &rarr; `notices.id` (`ON DELETE SET NULL`) — *Optional association; child entity remains valid even if parent link is removed.*
- **Referenced By (Incoming Foreign Keys)**:
  - `auction_lots.notice_id` (`ON DELETE CASCADE`)
  - `bids.notice_id` (`ON DELETE RESTRICT`)
  - `notice_documents.notice_id` (`ON DELETE CASCADE`)
  - `notices.canonical_id` (`ON DELETE SET NULL`)
  - `procurements.notice_id` (`ON DELETE RESTRICT`)

### `notification_deliveries`

- **Primary Key**: `id`
- **Columns Total**: 7
- **References (Outgoing Foreign Keys)**:
  - `notification_id` &rarr; `notifications.id` (`ON DELETE CASCADE`) — *Strict lifecycle child owned by parent record; purged upon parent deletion.*
- **Referenced By**: None (Leaf Entity)

### `notifications`

- **Primary Key**: `id`
- **Columns Total**: 9
- **References (Outgoing Foreign Keys)**:
  - `user_id` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By (Incoming Foreign Keys)**:
  - `notification_deliveries.notification_id` (`ON DELETE CASCADE`)

### `orders`

- **Primary Key**: `id`
- **Columns Total**: 12
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `user_id` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `organisations`

- **Primary Key**: `id`
- **Columns Total**: 19
- **References (Outgoing Foreign Keys)**:
  - `district_id` &rarr; `districts.id` (`ON DELETE SET NULL`) — *Optional association; child entity remains valid even if parent link is removed.*
- **Referenced By (Incoming Foreign Keys)**:
  - `alert_profiles.org_id` (`ON DELETE RESTRICT`)
  - `api_keys.org_id` (`ON DELETE RESTRICT`)
  - `awards.supplier_org_id` (`ON DELETE RESTRICT`)
  - `bids.org_id` (`ON DELETE RESTRICT`)
  - `contracts.org_id` (`ON DELETE RESTRICT`)
  - `contracts.supplier_org_id` (`ON DELETE RESTRICT`)
  - `data_requests.org_id` (`ON DELETE RESTRICT`)
  - `doc_purchases.buyer_org_id` (`ON DELETE RESTRICT`)
  - `document_assets.org_id` (`ON DELETE RESTRICT`)
  - `document_downloads.org_id` (`ON DELETE RESTRICT`)
  - `event_ledger.org_id` (`ON DELETE RESTRICT`)
  - `invitations.org_id` (`ON DELETE RESTRICT`)
  - `kyc_submissions.org_id` (`ON DELETE RESTRICT`)
  - `notices.org_id` (`ON DELETE RESTRICT`)
  - `notifications.org_id` (`ON DELETE RESTRICT`)
  - `orders.org_id` (`ON DELETE RESTRICT`)
  - `payments.org_id` (`ON DELETE RESTRICT`)
  - `procurement_plans.org_id` (`ON DELETE RESTRICT`)
  - `procurements.org_id` (`ON DELETE RESTRICT`)
  - `signatures.org_id` (`ON DELETE RESTRICT`)
  - `users.org_id` (`ON DELETE RESTRICT`)
  - `webhooks.org_id` (`ON DELETE RESTRICT`)

### `password_resets`

- **Primary Key**: `id`
- **Columns Total**: 5
- **References**: None (Base/Root Entity)
- **Referenced By**: None (Leaf Entity)

### `payments`

- **Primary Key**: `id`
- **Columns Total**: 17
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `user_id` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `procurement_plans`

- **Primary Key**: `id`
- **Columns Total**: 23
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `category_id` &rarr; `categories.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `created_by` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `approved_by` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `procurements`

- **Primary Key**: `id`
- **Columns Total**: 16
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `notice_id` &rarr; `notices.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `created_by` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `approved_by` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `published_by` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By (Incoming Foreign Keys)**:
  - `addenda.procurement_id` (`ON DELETE RESTRICT`)
  - `awards.procurement_id` (`ON DELETE RESTRICT`)
  - `bid_seals.procurement_id` (`ON DELETE RESTRICT`)
  - `clarifications.procurement_id` (`ON DELETE RESTRICT`)
  - `coi_declarations.procurement_id` (`ON DELETE RESTRICT`)
  - `complaints.procurement_id` (`ON DELETE RESTRICT`)
  - `contracts.procurement_id` (`ON DELETE RESTRICT`)
  - `doc_purchases.procurement_id` (`ON DELETE RESTRICT`)
  - `eval_criteria.procurement_id` (`ON DELETE RESTRICT`)
  - `submissions.procurement_id` (`ON DELETE RESTRICT`)
  - `tco_assessments.procurement_id` (`ON DELETE RESTRICT`)
  - `tender_keys.procurement_id` (`ON DELETE RESTRICT`)

### `provinces`

- **Primary Key**: `id`
- **Columns Total**: 3
- **References**: None (Base/Root Entity)
- **Referenced By (Incoming Foreign Keys)**:
  - `districts.province_id` (`ON DELETE SET NULL`)

### `ratings`

- **Primary Key**: `id`
- **Columns Total**: 8
- **References (Outgoing Foreign Keys)**:
  - `award_id` &rarr; `awards.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `refresh_tokens`

- **Primary Key**: `id`
- **Columns Total**: 8
- **References (Outgoing Foreign Keys)**:
  - `user_id` &rarr; `users.id` (`ON DELETE CASCADE`) — *Strict lifecycle child owned by parent record; purged upon parent deletion.*
- **Referenced By**: None (Leaf Entity)

### `security_events`

- **Primary Key**: `id`
- **Columns Total**: 7
- **References (Outgoing Foreign Keys)**:
  - `actor_id` &rarr; `users.id` (`ON DELETE SET NULL`) — *Optional association; child entity remains valid even if parent link is removed.*
- **Referenced By**: None (Leaf Entity)

### `signatures`

- **Primary Key**: `id`
- **Columns Total**: 11
- **References (Outgoing Foreign Keys)**:
  - `signer_id` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `submissions`

- **Primary Key**: `id`
- **Columns Total**: 15
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By (Incoming Foreign Keys)**:
  - `awards.submission_id` (`ON DELETE RESTRICT`)
  - `bid_seals.submission_id` (`ON DELETE RESTRICT`)
  - `eval_scores.submission_id` (`ON DELETE CASCADE`)

### `tco_assessments`

- **Primary Key**: `id`
- **Columns Total**: 6
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
  - `created_by` &rarr; `users.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `tender_keys`

- **Primary Key**: `id`
- **Columns Total**: 6
- **References (Outgoing Foreign Keys)**:
  - `procurement_id` &rarr; `procurements.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By**: None (Leaf Entity)

### `users`

- **Primary Key**: `id`
- **Columns Total**: 15
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By (Incoming Foreign Keys)**:
  - `alert_profiles.user_id` (`ON DELETE RESTRICT`)
  - `coi_declarations.user_id` (`ON DELETE RESTRICT`)
  - `contract_variations.created_by` (`ON DELETE RESTRICT`)
  - `contracts.created_by` (`ON DELETE RESTRICT`)
  - `data_requests.user_id` (`ON DELETE RESTRICT`)
  - `document_downloads.user_id` (`ON DELETE RESTRICT`)
  - `event_ledger.actor_id` (`ON DELETE RESTRICT`)
  - `legal_holds.created_by` (`ON DELETE RESTRICT`)
  - `notifications.user_id` (`ON DELETE RESTRICT`)
  - `orders.user_id` (`ON DELETE RESTRICT`)
  - `payments.user_id` (`ON DELETE RESTRICT`)
  - `procurement_plans.created_by` (`ON DELETE RESTRICT`)
  - `procurement_plans.approved_by` (`ON DELETE RESTRICT`)
  - `procurements.created_by` (`ON DELETE RESTRICT`)
  - `procurements.approved_by` (`ON DELETE RESTRICT`)
  - `procurements.published_by` (`ON DELETE RESTRICT`)
  - `refresh_tokens.user_id` (`ON DELETE CASCADE`)
  - `security_events.actor_id` (`ON DELETE SET NULL`)
  - `signatures.signer_id` (`ON DELETE RESTRICT`)
  - `tco_assessments.created_by` (`ON DELETE RESTRICT`)

### `webhook_deliveries`

- **Primary Key**: `id`
- **Columns Total**: 8
- **References (Outgoing Foreign Keys)**:
  - `webhook_id` &rarr; `webhooks.id` (`ON DELETE CASCADE`) — *Strict lifecycle child owned by parent record; purged upon parent deletion.*
- **Referenced By**: None (Leaf Entity)

### `webhooks`

- **Primary Key**: `id`
- **Columns Total**: 7
- **References (Outgoing Foreign Keys)**:
  - `org_id` &rarr; `organisations.id` (`ON DELETE RESTRICT`) — *Auditable procurement, regulatory, or financial record; parent deletion prohibited to maintain integrity.*
- **Referenced By (Incoming Foreign Keys)**:
  - `webhook_deliveries.webhook_id` (`ON DELETE CASCADE`)

---

## 3. SQLite vs MySQL 8 Portability Verification Matrix

| Dialect Feature | SQLite Development | MySQL 8 Production | Status / Solution |
|---|---|---|---|
| **Date/Time Functions** | `datetime('now')` | `NOW()` / `CURRENT_TIMESTAMP` | **RESOLVED**: Application-level timestamp binding (`date('Y-m-d H:i:s')`) |
| **Foreign Key Checks** | Inactive by default (`PRAGMA foreign_keys = OFF`) | Active by default (`FOREIGN_KEY_CHECKS = 1`) | **CONFIGURED**: SQLite `.env` enables `database.default.foreignKeys = true` |
| **Auto Increment** | `AUTOINCREMENT` / rowid | `AUTO_INCREMENT` | **PORTABLE**: CI4 Forge maps `auto_increment => true` seamlessly |
| **Full Group By** | Relaxed | `ONLY_FULL_GROUP_BY` strict | **RESOLVED**: All queries explicitly group by selected non-aggregate columns |
| **Sequence Reset** | `DELETE FROM sqlite_sequence` | `ALTER TABLE t AUTO_INCREMENT = 1` | **GUARDED**: Guarded by `if ($db->DBDriver === 'SQLite3')` in seeders |
