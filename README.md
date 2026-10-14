# Poss POS Platform

Poss is a full-stack point-of-sale platform foundation for small and medium-sized businesses. The repository separates the Laravel REST API from the React web application and establishes a clean versioned API, token authentication, tenant membership checks, and a production-minded business workspace.

## Architecture

```text
Browser (React + Vite)
  -> TanStack Query -> Axios API client
  -> Laravel REST API (/api/v1)
  -> Sanctum personal access tokens and business membership middleware
  -> PostgreSQL 16; Redis for cache, queue, and sessions
```

The REST API is the source of truth for business data and tenant access. The existing application is being extended incrementally; configured payment methods do not imply a live payment-provider integration or verified compliance.

## Technology Stack

- PHP 8.4, Laravel 12+ / 13, Laravel Sanctum, PHPUnit
- PostgreSQL 16, Redis 7, Laravel queue/cache/session drivers
- React 19, Vite, TypeScript, Tailwind CSS 4
- React Router, TanStack Query, Axios, React Hook Form, Zod, Recharts, Lucide React

## Quick Start With Docker

Prerequisites: Docker Desktop with Compose support.

1. Copy `.env.example` to `.env` in the repository root.
2. Start the services:

   ```powershell
   docker compose up --build
   ```

3. Open the frontend at `http://localhost:5173`.
4. The backend is available at `http://localhost:8000/api/v1`.
5. Stop services with `docker compose down`.

The backend container runs migrations automatically on startup. The API exposes `GET /health` and the business auth flow through `POST /api/v1/auth/register-business` and `POST /api/v1/auth/login`.

## Local Development Without Docker

Requirements: PHP 8.4, Composer 2, PostgreSQL 16, Redis, Node.js 24+, npm 11+.

Backend:

```powershell
Set-Location backend
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Frontend:

```powershell
Set-Location frontend
Copy-Item .env.example .env
npm ci
npm run dev
```

## Environment Variables

### Backend

```env
APP_KEY=
APP_ENV=local
APP_URL=http://localhost:8000
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pos_platform
DB_USERNAME=pos
DB_PASSWORD=your-dev-password
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.your-provider.example
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=verified-sender@your-domain.example
MAIL_FROM_NAME=Poss
```

The example SMTP host, username, password, and sender above are placeholders. Replace them with the SMTP settings and a verified sender from your email provider. For local development without SMTP, `MAIL_MAILER=log` writes messages to the backend log and does **not** deliver email. When using Docker Compose, set these values in the repository-root `.env`; for local backend development, set them in `backend/.env`. After changing mail settings, clear cached configuration with `php artisan config:clear` in `backend/` (or `docker compose exec backend php artisan config:clear` for Docker) and restart the backend. Team invitations are sent when an owner or admin adds a staff member; the recipient address must be able to receive mail from your configured sender.

### Frontend

```env
VITE_API_URL=http://localhost:8000/api/v1
```

Never commit secrets or production credentials.

## Authentication

The POS foundation supports:

- `POST /api/v1/auth/register-business`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/user`
- `GET /api/v1/business/current`

The business context middleware requires the `X-Business-ID` header and enforces membership validation. This ensures tenants are isolated and Business A cannot access Business B records.

## Product barcodes, purchases, and sales

Create a separate product record for each sellable item/SKU, even when several products share a category. Each record keeps its own SKU, name, shared product barcode (when applicable), price, and stock count. A sale records one line per product with its product ID, SKU/name snapshot, barcode, quantity, and price; products in the same category are not merged.

For clothing and other variant-based catalogs, create a separate product/SKU for every sellable size-colour combination and set its variant attributes (for example, `Size=M`, `Colour=Blue`). Each combination then has independent stock, pricing, barcode, and sales history.

When adding a product, select its barcode tracking mode:

- **One shared barcode per product:** for normal retail, grocery, and wholesale goods where every unit of the same SKU has the same barcode. Scan it at checkout to add one unit; the sale groups units under that product and stock is tracked by quantity. Receive stock by quantity through Purchases or adjust stock in Inventory.
- **Unique barcode per physical unit:** for serialized stock such as phones, equipment, or individually identified items. Scan a unique barcode for every received unit in Inventory or include one barcode per quantity on a purchase. Checkout requires scanning the in-stock unit barcode and marks that exact unit sold.

Both modes use the same product/category catalog and inventory ledger. Barcode values must be unique within a business, and purchase receipt, stock movements, and checkout update atomically.

Pharmacy inventory also shows remaining units in expired batches and batches expiring within 30 days. Batch rows display days to expiry, and expired stock remains excluded from checkout.

### Cash refunds

An authorized role with `sales.refund` can record a cash return against a paid cash sale. The backend limits returns to the unreturned quantity, requires matching serials for serialized products, restores stock and original pharmacy batches, decreases the open register's expected cash, and writes a tenant-scoped audit record in the same database transaction. An open register with sufficient expected cash is required. Credit and electronic-payment refunds are not offered until their settlement/provider workflows exist; a UI setting for a payment method does not settle funds.

### Restaurants and service businesses

- **Restaurants and cafes:** Record dine-in, takeaway, or delivery tickets from the POS using **Send to kitchen**. Open tickets reserve available goods stock, can progress through the kitchen statuses, and can be cancelled to release reservations. Collect cash from Kitchen Orders when the ticket is ready to close. The table field is a free-text label; managed floor plans and table assignments are not included yet. Ticket item prices are captured when the order is placed.
- **Barbershops and other appointment services:** Create a service product (non-stock item), then book it on Appointments with a team member, customer, and start time. The schedule blocks overlapping appointments for the same team member. Service items can also be rung up directly in the POS and assigned to a team member; they do not consume inventory or appear in Purchases.
- **General POS use:** Standard product sales remain the default checkout path. Service, restaurant, inventory, and purchase workflows use the same business catalog, customer records, register, and reporting foundation; industry-specific scheduling, kitchen tickets, and staff assignment are optional.

## Database Setup and Migrations

- PostgreSQL is the configured application database.
- Business records use UUID identifiers.
- Business membership is stored through the `business_user` pivot table.
- Apply schema changes with `php artisan migrate` from `backend/`.
- For an empty PostgreSQL 16 database, configure the database and run `php artisan migrate --force` from `backend/`; Laravel migrations are the canonical schema source for both fresh installations and upgrades.
- [documentation/postgresql-schema.sql](documentation/postgresql-schema.sql) is a legacy baseline snapshot, not a complete representation of current application migrations. Do not use it to provision a current installation or run it against a database that already has application tables.

## Verified capabilities and current boundaries

The backend currently provides business registration and profiles, business-scoped membership authorization, business switching, built-in/custom roles, industry/module configuration, Starter subscription records with product/user limits, catalog and shared/serialized barcode inventory, purchases, suppliers, customers, expenses, cash register sessions, checkout with configured tax/discount rules, reports, restaurant order tickets, service appointments, pharmacy expiry batches with expiry alerts and FEFO checkout, wholesale quantity tiers, and the cash-only refund workflow above. Existing migrations already define warehouses, units, payments, refunds, audit logs and webhooks; the existence of those tables is not evidence that all corresponding workflows are complete.

The following remain incomplete and must not be represented as production-ready: branch-level stock and branch permissions; independently managed variant SKU records beyond one product per sellable variant; unit conversions; electronic payment processing/reconciliation, idempotent provider webhooks and payment refunds; payment records for checkout; kitchen modifiers, recipes, ingredient costing, split bills, delivery tracking and wastage; salon walk-in queues, staff scheduling, commissions and reminders; pharmacy prescriptions/dispensing and jurisdiction-specific regulatory validation; promotions/loyalty; customer-specific wholesale pricing, quotations, credit limits and delivery records; general audit coverage beyond cash refunds; and integration to printers, SMS, WhatsApp or SellFlux. Tax configuration is not certification of tax compliance. Validate legal and regulatory requirements with qualified local providers before production use.

Backend tests use the configured SQLite test database. A live PostgreSQL migration/upgrade run and end-to-end browser-to-API test have not been performed as part of this change.

## Seeders and Demo Data

The system includes a development-safe foundation and supports realistic Kenyan demo data patterns for:

- businesses
- users
- owners/admins/managers/cashiers/inventory roles
- products, categories, warehouses, inventory
- customer and supplier records
- a demo retail setup for local development

Do not use real production user data or payment credentials in the repository.

## Docker

The Compose file configures:

- PostgreSQL 16
- Redis 7
- Laravel backend container
- React frontend container

Run:

```powershell
docker compose up -d
```

## Frontend Structure

The React workspace is organized to scale with the POS domain, including:

- `src/api`
- `src/features/auth`
- `src/lib`
- `src/App.tsx`
- `src/index.css`

The UI uses a premium graphite/champagne/lime design system and protects the auth shell with a proper login flow.

## API Documentation

The OpenAPI contract is in [documentation/openapi.yaml](documentation/openapi.yaml).

## Testing

Backend tests cover registration, login, logout, and tenant/business isolation:

```powershell
Set-Location backend
php artisan test
```

Frontend build verification:

```powershell
Set-Location frontend
npm run build
```

## Security and Production Guardrails

- Use managed secrets in production.
- Do not expose private keys or payment credentials in browser code.
- Keep backend authorization and inventory rules authoritative.
- Business owners and admins can configure the business profile, currency, time zone, sales tax, accepted payment methods, and printed receipt details in Settings. Checkout enforces enabled tenders and calculates tax on the server.
- Enforce business membership checks on every tenant-specific endpoint.
- The frontend is for UX and workflow orchestration only, never the final authority for price, tax, stock, or payment status.

## SellFlux Integration Architecture

The POS remains the transactional source of truth. SellFlux may later consume versioned, authorized data from a separate integration endpoint. The design stays decoupled from the user-facing POS logic so product, sales, customer, inventory, and payment data can be synchronized without tight direct coupling.

## Troubleshooting

- If the frontend cannot reach the API, confirm `VITE_API_URL`.
- If login fails, validate the backend environment and database connectivity.
- Apply non-destructive migrations with `php artisan migrate` in the backend container or local PHP environment. Do not run `migrate:fresh` or remove Docker volumes against a database containing data you need.
- For Docker resets, use `docker compose down -v` when you explicitly want to remove persistent database data.

## Deployment Notes

Production deployment should include:

- TLS termination
- separate environment variables and secrets
- Redis queue workers
- HTTP rate limiting
- cron/scheduler for recurring jobs
- backup strategy for PostgreSQL
- monitoring and health checks
- explicit CORS and origin policy

This repository is a working POS foundation with the boundaries listed above; production rollout still requires PostgreSQL upgrade verification, external payment/tax integrations, and the applicable industry and jurisdiction-specific workflows.

## Final Acceptance Workflow

The system supports the foundational acceptance flow:

1. Create a business
2. Register admin and login
3. Access the dashboard shell
4. Work through the POS workspace and auth flows
5. Verify API connectivity and business membership enforcement

This is the foundation from which the full transactional POS system can be expanded responsibly and securely.
