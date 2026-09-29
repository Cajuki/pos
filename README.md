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

The REST API is the source of truth for business data and tenant access. A full commercial catalog, inventory ledger, checkout, payment gateway integration, and market intelligence layer are designed for extension without coupling the POS to SellFlux or other downstream systems.

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
```

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

## Database Setup and Migrations

- PostgreSQL is the configured application database.
- Business records use UUID identifiers.
- Business membership is stored through the `business_user` pivot table.
- Apply schema changes with `php artisan migrate` from `backend/`.
- For an empty PostgreSQL 16 database, the complete SQL schema is available at [documentation/postgresql-schema.sql](documentation/postgresql-schema.sql). Run it with `psql -v ON_ERROR_STOP=1 -d pos_platform -f documentation/postgresql-schema.sql` after creating the database. It includes the migration ledger for the schema represented in the script.
- Use Laravel migrations for existing installations and future schema upgrades; do not run the fresh-install SQL against a database that already has application tables.

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
- Enforce business membership checks on every tenant-specific endpoint.
- The frontend is for UX and workflow orchestration only, never the final authority for price, tax, stock, or payment status.

## SellFlux Integration Architecture

The POS remains the transactional source of truth. SellFlux may later consume versioned, authorized data from a separate integration endpoint. The design stays decoupled from the user-facing POS logic so product, sales, customer, inventory, and payment data can be synchronized without tight direct coupling.

## Troubleshooting

- If the frontend cannot reach the API, confirm `VITE_API_URL`.
- If login fails, validate the backend environment and database connectivity.
- If migration errors occur, run `php artisan migrate:fresh --seed` in the backend container or local PHP environment.
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

This repository is intentionally organized as a commercial-start foundation rather than a toy demo, and the final application should be extended with catalog, sales, inventory, payment, reporting, and integrations as required by the business product roadmap.

## Final Acceptance Workflow

The system supports the foundational acceptance flow:

1. Create a business
2. Register admin and login
3. Access the dashboard shell
4. Work through the POS workspace and auth flows
5. Verify API connectivity and business membership enforcement

This is the foundation from which the full transactional POS system can be expanded responsibly and securely.

