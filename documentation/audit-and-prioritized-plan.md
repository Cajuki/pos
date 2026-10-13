# SellFlux POS audit and prioritized implementation plan

**Audit date:** 13 October 2026

**Scope:** Static review of the Laravel API, migrations, permissions, React application, and existing tests. No production database or payment-provider credentials were available, so this is not a live deployment, security penetration, or compliance assessment.

## Existing foundation

- Laravel API v1 with Sanctum bearer tokens, throttled login and registration, business membership middleware, and configurable role permissions.
- PostgreSQL-oriented migrations with tenant keys, unique constraints, inventory stocks and movements, purchases, suppliers, cash registers, sale/refund records, audit logs, pharmacy batches, restaurant tickets, appointments, and subscription plans.
- Transactional checkout and refund code with row locks; existing feature tests cover core stock, cash register, discounts, refund, permissions, invitations, and basic tenant isolation.
- React, Vite, TypeScript, and Tailwind frontend with a consistent champagne, lime, and graphite visual system, plus retail, restaurant, and appointment workflows.

## Prioritized findings

### P0 — payment and checkout correctness

- Checkout now accepts a business-scoped `Idempotency-Key`, returns the original sale for a matching retry, and rejects reuse with a different payload. Checkout remains transactional and locks stock. Payment-provider attempts, callbacks, settlement, and reconciliation are not implemented, so configured non-cash tenders are not verified digital payments.
- `mpesa`, `card`, and `bank` tenders are recorded as paid directly by checkout. The code reviewed does not initiate Daraja STK push, receive provider callbacks, authenticate callbacks, deduplicate provider events, or reconcile settlement. Treat these entries as manually confirmed tenders; do not represent them as a live M-Pesa integration.
- `credit` checkout is recorded as `on_credit`, but collection, aging, limits, and settlement workflows were not found in the reviewed routes.
- Tax settings are a single configurable rate. No KRA eTIMS adapter, fiscal device workflow, certification evidence, or approval state was found. Do not claim eTIMS compliance.

### P1 — tenant, access, and operational controls

- Business membership is enforced centrally and most resource queries inspected scope by `business_id`; the feature suite includes a cross-business product/sale check. Continue verifying every new relationship and every write path at the query and database-constraint layers.
- Role-based API permissions exist, including custom roles, but a full route/action authorization matrix and access tests for every resource are not present in the reviewed suite. Platform-admin access is intentionally broad and merits separate operational controls and audit review.
- React stores bearer tokens in `localStorage`, exposing them to any successful same-origin script injection. Prefer a hardened, HttpOnly cookie/BFF session design before production internet exposure.
- Audit records are written for refunds and every stock ledger movement (adjustment, receipt, sale deduction, or refund restoration). A consistent trail for settings, staff/role changes, cash sessions, and other sensitive changes still needs extension.
- Multiple tills and shift selection are implemented within a business, with sale-to-till linkage and till-specific refund reconciliation. Branch and warehouse APIs plus branch assignments for tills/warehouses are now present. Inventory quantities and stock movements still remain business-wide, so checkout, purchasing, transfers, permissions, and reporting are not yet branch-aware.

### P2 — product scope and data integrity

- Working modules include product/catalog management, stock adjustments and movements, purchase receiving, suppliers, cash sales/refunds, expenses, reports, restaurant kitchen tickets, appointments, and basic pharmacy batch/expiry tracking. The UI and route inventory are not yet a complete implementation of the requested wholesale, supermarket, salon commission, pharmacy dispensing, quotation, import, backup, or owner-dashboard workflows.
- Industry profiles currently select a limited set of modules; they are not yet a general reusable industry-configuration system.
- Existing tests do not establish M-Pesa callback safety, branch transfers, database-level tenant isolation, concurrent checkout behavior, offline operation, backup restore, hardware compatibility, or report reconciliation across all transaction types.
- Deployment documentation covers basic environment setup. A tested restore runbook, monitoring/alerting guidance, payment integration operations, and production backup verification remain to be added.

## Implementation order

1. **Checkout reliability:** add idempotent sale submission and regression tests while preserving existing records. Then cover concurrent stock contention and ensure only finalized tenders affect confirmed revenue.
2. **Authorization and audit:** build a route/action permission matrix; add tests for tenant boundaries and permissions; add auditable records for sensitive mutations.
3. **Payments and finance:** model payment attempts and settlement states; add Daraja behind an adapter with signed/validated callback processing, idempotency, retries, and reconciliation. Keep credentials optional and all non-live behavior clearly labeled. Define eTIMS requirements and certification gates before making compliance claims.
4. **Locations and SaaS controls:** move stock and movements to warehouse locations, bind sales/purchases/transfers to authorized branches, implement branch-level access and owner dashboards, then complete entitlements, import validation, monitoring, and tested backup/restore procedures.
5. **Industry workflows and intelligence:** expand only against validated core records, with focused workflows for supported industries and authorized, retried, duplicate-safe analytics events.
6. **Release verification:** add and run targeted tests per phase, then broader PostgreSQL-backed checks for concurrency, isolation, reconciliation, restore, offline behavior where supported, and hardware before claiming those capabilities.

## First implementation phase (complete)

Phase 1 adds an optional `Idempotency-Key` to sale checkout. Replaying the same key and payload returns the original sale without changing stock or cash; reusing that key with a different payload returns a conflict. Existing rows remain valid because the migration adds nullable columns.

## Second implementation phase

Stock movements now trigger an actor-attributed `inventory.stock_movement_recorded` audit event in the same database transaction. The audit payload records the product, linked sale where present, movement type, quantity delta, before/after quantities, and reason. A feature test verifies the adjustment actor and quantity data; the full backend suite passes against the configured in-memory SQLite test database. PostgreSQL concurrency and production migration execution remain release checks.

## Third implementation phase

Businesses can create multiple tills, list and select active tills, open concurrent shifts on different tills, and close/reconcile a selected shift. New sales retain a nullable `cash_register_id`; cash refunds use the originating sale's till. Historical sales remain unchanged and keep the legacy default-till refund path. Opening and closing shifts are audit logged. Managers can create tills; cashier roles can list/select tills and operate shifts but cannot create tills. Tills are assigned to branches by the fourth phase. The migration and feature suite pass on SQLite; high-contention PostgreSQL session-open races still need a PostgreSQL integration test.

## Fourth implementation phase

Branches are modeled per business, and warehouses and cash registers now reference a branch through composite `(business_id, branch_id)` foreign keys. The migration backfills one `Main Branch` for every existing business, attaches its existing warehouses and tills, and creates a default warehouse only where a business had none. New business registration creates the same initial branch and warehouse. Tenant-scoped branch and warehouse APIs support branch creation, activation/default selection, and warehouse listing/creation; custom roles can be given separate read/manage permissions. Feature tests cover branch/warehouse creation, till assignment, cross-business branch rejection, and cashier denial of branch creation.

This phase does not move `inventory_stocks` or `stock_movements` to warehouse scope. Checkout, refunds, purchase receiving, batch/serialized stock, restaurant reservations, and reports still use business-wide quantities. The branch APIs therefore establish location records and relationships; they do not yet provide branch-isolated inventory operations. PostgreSQL production migration and concurrency checks remain unverified.

## Fifth implementation phase: restaurant floor foundation

Restaurant tables now belong to a business branch, carry an area and seating capacity, and track `available`, `occupied`, `reserved`, or `cleaning` separately from kitchen order/payment status. A dine-in order can claim one available managed table; the server locks it and rejects a second active order. The order must use a till in the same branch. Payment or cancellation moves the table to cleaning, and staff can return it to available after cleaning. The POS selects available tables, while the kitchen screen can add tables, reserve/release them, and mark cleaning complete. Historical text-only `table_name` orders remain readable and can still be created for compatibility.

Focused restaurant-table tests cover occupancy, duplicate claims, payment/cancellation cleanup, and branch/till mismatch. This remains a restaurant foundation, not the full requested module. Waiter assignment, real reservation scheduling, adding items to an open order, item-level kitchen/bar routing and states, fire-again/void workflows, table moves/merges, split payments/bills, recipe-based ingredient deductions and costing, and restaurant-specific reports are outstanding. PostgreSQL lock behavior and live multi-device floor behavior have not been verified.

## Restaurant scope status

The existing flow and fifth phase now support dine-in, takeaway, and delivery orders, product and item notes, stock reservation, order-level open/preparing/ready/served/cancelled states, a polling kitchen queue, managed branch-bound tables, table occupancy lifecycle, cancellation stock release, and payment through regular checkout. The `table_name` field remains for old and unmanaged orders; it is not the managed table identity. Do not claim the complete restaurant module until the outstanding work listed above is implemented and verified.

## Pharmacy catalogue foundation

Products now support optional medicine catalogue fields for brand and generic names, active ingredients, strength, dosage form, pack size, unit of sale, manufacturer, registration number, classification, and prescription requirement. Fields are accepted only when the business has the pharmacy module enabled. The pharmacy product form exposes these fields. Checkout blocks prescription-required products because the prescription verification and dispensing workflow is not yet implemented; no prescription is assumed valid from a product or sale request. Feature coverage verifies tenant module gating, field persistence, blocked checkout, and unchanged batch balances after a rejected sale.

This does not yet provide unit conversions, supplier linkage on batches, quarantine/recall, prescriptions, patient records, authorization, partial dispensing, or pharmacy reporting. Prescription-classified stock remains unavailable for sale until a verified dispensing flow is implemented.

## Salon and barbershop foundation

The industry list now includes a Barbershop profile using the appointments module. Service products support duration, booking buffer, and optional fixed or percentage commission rules. Staff can be assigned branch-specific weekly hours and breaks; when a staff member has a configured schedule, booking requires a matching working-hour row. The backend locks the staff member during booking, checks time overlap including the service buffer across that staff member's branches, and supports branch-scoped appointments. Booked appointments can be rescheduled, checked in, cancelled, marked no-show, started, and checked out. Walk-ins enter as checked-in appointments.

Commission entries are created once for paid sales with an assigned staff member and a product rule. Refunds create proportional, auditable reversals. Managers can approve entries and record payout references; this records the payout reference and status, separate from POS payment collection. Focused tests cover schedules, breaks, buffers, rescheduling, walk-in queue state, cross-tenant branch rejection, commission creation, approval, payout, and refund reversal.

This foundation does not yet provide leave and shift records, staff-specific service eligibility, separate branch-specific service catalogues, service packages, promotions, configurable commission rules by staff/service/category, membership or loyalty balances, consumables per service, customer retention analytics, or SMS/email/WhatsApp integrations. The appointment view filters day/week/staff data but is not a drag-and-drop calendar. Automated PostgreSQL concurrency and external payment settlement remain unverified.

## Evidence limits

This audit reflects source inspection and existing test definitions. Features listed as present are implemented in the repository but are not asserted production-ready solely by this document. Daraja credentials, live callback delivery, KRA testing/certification, production PostgreSQL concurrency, backup restoration, and hardware were not available for verification.

## Requirement coverage matrix

| Area | Present in repository | Material gaps to close |
| --- | --- | --- |
| Multi-tenant businesses | UUID businesses, membership pivot, roles, request business context, tenant-filtered resource queries, settings and plan/limit foundation; branches, branch-assigned warehouses/tills, multiple business-scoped tills | Branch-level user access, stock by location, comprehensive tenant tests, platform support dashboard, subscription lifecycle and complete entitlements |
| Sales and checkout | Search/barcode lookup, basket, discounts, configurable tax, cash tender/change, customer link, receipts, refunds, service checkout and restaurant checkout | Verified digital settlement, split tenders, suspended/resumed sales, quotations/orders/delivery notes, price override approvals, credit collection, credit notes |
| Inventory and procurement | Products/categories, stock ledger, adjustments, purchases/suppliers, low-stock reporting, serial barcodes, FEFO batch allocation | Route-backed branch transfers, stocktake/wastage/returns, supplier balances, unit conversion, valuation/ageing, warranty, and purchase invoice/Goods Received Note workflows |
| Restaurant | Open tickets, basic dine-in/takeaway/delivery, kitchen order statuses, stock reservation, branch-bound tables and occupancy/cleaning states, cash closeout | Menus/modifiers/recipes, reservations, waiters, adding to open orders, table transfers/merges, item routing/status, printer recovery, split/partial bills, service charges, restaurant reporting |
| Industry profiles | Industry selection, pharmacy medicine catalogue and FEFO batches, salon/barbershop branch schedules, appointment lifecycle, service buffers, paid-sale commissions and refund reversals, wholesale quantity tiers | Verified prescription/dispensing, pharmacy recalls/reports, salon leave/shifts, service packages and staff eligibility, loyalty/memberships, consumables, external notifications, hardware quotations/LPO/delivery, electronics warranty, hotel workflows |
| Kenya payments and taxes | Tender settings and KES default; ordinary POS tax calculation | Daraja adapter/callback/reconciliation; tax categories; eTIMS invoice submission and status/correction flow; certification |
| Accounting | Expenses, cash register expected-cash tracking, revenue/refund summaries, estimated margin | Ledgers/journals, receivables/payables, allocation, bank reconciliation, trial balance, balance sheet, cash flow, inventory valuation, tax reconciliation |
| Customer, staff, CRM | Customer records, business invitations, role permissions, appointments/provider assignment, audit log table | Credit limits/statements, loyalty/consent, attendance/commission/approval/leave workflows, broad sensitive-action auditing |
| Intelligence and connectors | Dashboard/report summaries and a plan for analytics | Validated event outbox, documented authorized event API, retry/sync visibility, external POS/e-commerce adapters and reconciliation |
| Hardware and offline | Barcode input through browser keyboard events | Tested printer/drawer/scale/device support and an explicit offline queue/sync/conflict model |
| Security/reliability | Sanctum tokens, login throttling, membership and permission middleware, validation, several atomic workflows | Reduce localStorage token exposure; validate every authorization edge; secure business/provider secrets; backups/restore, monitoring, retention controls and concurrency checks on PostgreSQL |
| Onboarding and subscriptions | Registration, industry selection, invitations, starter plan and product/user limits | Branch/till and configuration wizard, import templates, hardware/integration setup, test transaction checklist, renewal state and support progress tools |
| Quality assurance | Feature tests for auth, tenant membership, products, checkout, stock, refunds, settings, subscriptions, invitations, restaurant table occupancy, salon scheduling, commission payout and reversal | PostgreSQL concurrency, transfers, callbacks, restaurant recipes, financial reconciliation, backup restore, offline/hardware acceptance tests |

## Schema changes required by the full brief

The checkout reliability phase adds nullable `sales.idempotency_key` and `sales.idempotency_hash`, with a business-scoped unique index. Existing rows remain valid. The location phase adds `branches`, backfills one default branch per existing business, assigns existing warehouses and tills to it, and adds a default warehouse when none exists. It leaves the business-wide stock rows intact. Restaurant foundation adds managed tables tied to branch and order records. Next, stock must be migrated into warehouse-scoped rows with an audited opening transfer/backfill, followed by location-aware checkout, returns, purchasing, batch/serialized inventory, restaurant reservations, and reports. Payment attempts and provider callback inbox/outbox records; invoice fiscal-submission records; and accounting journal tables are also outstanding. These changes must be introduced with forward-only data-preserving migrations and reconciliation checks before legacy columns or workflows are retired.

## Kenya integration readiness reviewed 12 October 2026

- Safaricom's official [Daraja developer portal](https://developer.safaricom.co.ke/) describes its API platform and sandbox app testing. The repository has no Daraja client, credentials, STK initiation, callback route, or transaction reconciliation. The payment work needs a tenant credential/onboarding decision, public HTTPS deployment, and sandbox/production credentials. Tests can validate state transitions and duplicate handling without live credentials; external transaction delivery still needs sandbox and production validation.
- KRA describes eTIMS system-to-system integration through OSCU for online invoicing or VSCU for certain bulk/offline use cases. KRA says self-integrators and third-party vendors must complete certification before commencing integration; its stated process includes sandbox development/testing, application, technical/administrative review, interim approval, and later final certification. See [KRA eTIMS overview](https://www.kra.go.ke/online-services/etims), [KRA eTIMS system-to-system and technical resources](https://www.kra.go.ke/services/service/16), and the [KRA OSCU specification](https://kra.go.ke/images/publications/OSCU_Specification_Document_v2.0.pdf). SellFlux has no eTIMS adapter, sandbox test evidence, or approval. External certification is a release gate; ordinary receipts must remain distinct from successfully fiscalized invoices.
- The current static tax rate is not a complete Kenyan tax configuration. Tax-category mappings, branch/taxpayer identity, invoice sequencing/corrections, and provider/KRA references require a product and compliance design based on the applicable current KRA specifications before implementation.
