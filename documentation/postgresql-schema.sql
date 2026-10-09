-- Poss POS Platform schema for PostgreSQL 16+.
-- Fresh database only. For an existing installation, use Laravel migrations instead.
-- Configure the target database before running: psql -v ON_ERROR_STOP=1 -d pos_platform -f documentation/postgresql-schema.sql

BEGIN;

CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE,
    password VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);

CREATE TABLE password_reset_tokens (
    email VARCHAR(255) PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE
);

CREATE TABLE sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id BIGINT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    payload TEXT NOT NULL,
    last_activity INTEGER NOT NULL
);
CREATE INDEX sessions_user_id_index ON sessions (user_id);
CREATE INDEX sessions_last_activity_index ON sessions (last_activity);

CREATE TABLE businesses (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'KES',
    timezone VARCHAR(255) NOT NULL DEFAULT 'Africa/Nairobi',
    status VARCHAR(255) NOT NULL DEFAULT 'active',
    settings JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);

CREATE TABLE categories (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    description TEXT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, name)
);

CREATE TABLE brands (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, name)
);

CREATE TABLE units (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    name VARCHAR(80) NOT NULL,
    abbreviation VARCHAR(16) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, name),
    UNIQUE (business_id, abbreviation)
);

CREATE TABLE warehouses (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(40) NOT NULL,
    address TEXT,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, code)
);
CREATE INDEX warehouses_business_id_is_active_index ON warehouses (business_id, is_active);

CREATE TABLE customers (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(40),
    address TEXT,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX customers_business_id_name_index ON customers (business_id, name);
CREATE INDEX customers_business_id_phone_index ON customers (business_id, phone);

CREATE TABLE suppliers (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(40),
    address TEXT,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX suppliers_business_id_name_index ON suppliers (business_id, name);

CREATE TABLE permissions (
    id BIGSERIAL PRIMARY KEY,
    key VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);

CREATE TABLE roles (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255),
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, name)
);

CREATE TABLE role_permissions (
    id BIGSERIAL PRIMARY KEY,
    role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (role_id, permission_id)
);

CREATE TABLE business_user (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role VARCHAR(255) NOT NULL DEFAULT 'cashier',
    role_id BIGINT REFERENCES roles(id) ON DELETE SET NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, user_id)
);

CREATE TABLE products (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    category_id BIGINT REFERENCES categories(id) ON DELETE SET NULL,
    brand_id BIGINT REFERENCES brands(id) ON DELETE SET NULL,
    unit_id BIGINT REFERENCES units(id) ON DELETE SET NULL,
    sku VARCHAR(64) NOT NULL,
    barcode VARCHAR(100),
    barcode_tracking_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(255),
    description TEXT,
    unit_price NUMERIC(12, 2) NOT NULL,
    cost_price NUMERIC(12, 2),
    reorder_level INTEGER NOT NULL DEFAULT 0 CHECK (reorder_level >= 0),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, sku)
);
CREATE INDEX products_business_id_name_index ON products (business_id, name);
CREATE INDEX products_business_id_barcode_index ON products (business_id, barcode);

CREATE TABLE inventory_stocks (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    quantity_on_hand INTEGER NOT NULL DEFAULT 0 CHECK (quantity_on_hand >= 0),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, product_id)
);

CREATE TABLE sales (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    customer_id BIGINT REFERENCES customers(id) ON DELETE SET NULL,
    receipt_number VARCHAR(40) NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'paid',
    subtotal NUMERIC(12, 2) NOT NULL,
    discount_amount NUMERIC(12, 2) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(12, 2) NOT NULL DEFAULT 0,
    tax_rate NUMERIC(5, 2) NOT NULL DEFAULT 0,
    total NUMERIC(12, 2) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, receipt_number)
);
CREATE INDEX sales_business_id_created_at_index ON sales (business_id, created_at);

CREATE TABLE sale_items (
    id BIGSERIAL PRIMARY KEY,
    sale_id BIGINT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    sku VARCHAR(64) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity >= 0),
    unit_price NUMERIC(12, 2) NOT NULL,
    unit_cost NUMERIC(12, 2),
    line_total NUMERIC(12, 2) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX sale_items_sale_id_index ON sale_items (sale_id);

CREATE TABLE product_barcodes (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    sale_item_id BIGINT REFERENCES sale_items(id) ON DELETE SET NULL,
    barcode VARCHAR(100) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'in_stock',
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, barcode)
);
CREATE INDEX product_barcodes_business_id_product_id_status_index ON product_barcodes (business_id, product_id, status);

CREATE TABLE stock_movements (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    inventory_stock_id BIGINT NOT NULL REFERENCES inventory_stocks(id) ON DELETE RESTRICT,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    sale_id BIGINT REFERENCES sales(id) ON DELETE RESTRICT,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    type VARCHAR(32) NOT NULL,
    quantity_change INTEGER NOT NULL,
    quantity_before INTEGER NOT NULL CHECK (quantity_before >= 0),
    quantity_after INTEGER NOT NULL CHECK (quantity_after >= 0),
    reason VARCHAR(255),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX stock_movements_business_id_created_at_index ON stock_movements (business_id, created_at);

CREATE TABLE purchase_orders (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    supplier_id BIGINT REFERENCES suppliers(id) ON DELETE SET NULL,
    warehouse_id BIGINT REFERENCES warehouses(id) ON DELETE SET NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    reference_number VARCHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    subtotal NUMERIC(19, 4) NOT NULL DEFAULT 0,
    discount_amount NUMERIC(19, 4) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(19, 4) NOT NULL DEFAULT 0,
    total NUMERIC(19, 4) NOT NULL DEFAULT 0,
    ordered_at TIMESTAMP(0) WITHOUT TIME ZONE,
    received_at TIMESTAMP(0) WITHOUT TIME ZONE,
    notes TEXT,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, reference_number)
);
CREATE INDEX purchase_orders_business_status_created_index ON purchase_orders (business_id, status, created_at);

CREATE TABLE purchase_items (
    id BIGSERIAL PRIMARY KEY,
    purchase_order_id BIGINT NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    product_id BIGINT REFERENCES products(id) ON DELETE SET NULL,
    sku VARCHAR(64),
    product_name VARCHAR(160) NOT NULL,
    quantity_ordered INTEGER NOT NULL CHECK (quantity_ordered >= 0),
    quantity_received INTEGER NOT NULL DEFAULT 0 CHECK (quantity_received >= 0),
    unit_cost NUMERIC(19, 4) NOT NULL,
    tax_amount NUMERIC(19, 4) NOT NULL DEFAULT 0,
    line_total NUMERIC(19, 4) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX purchase_items_purchase_order_id_index ON purchase_items (purchase_order_id);

CREATE TABLE payments (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    sale_id BIGINT REFERENCES sales(id) ON DELETE CASCADE,
    purchase_order_id BIGINT REFERENCES purchase_orders(id) ON DELETE SET NULL,
    customer_id BIGINT REFERENCES customers(id) ON DELETE SET NULL,
    processed_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    method VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    amount NUMERIC(19, 4) NOT NULL,
    provider_reference VARCHAR(120),
    idempotency_key VARCHAR(120),
    metadata JSON,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, idempotency_key)
);
CREATE INDEX payments_business_status_created_index ON payments (business_id, status, created_at);
CREATE INDEX payments_business_provider_reference_index ON payments (business_id, provider_reference);

CREATE TABLE refunds (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    sale_id BIGINT NOT NULL REFERENCES sales(id) ON DELETE RESTRICT,
    payment_id BIGINT REFERENCES payments(id) ON DELETE SET NULL,
    processed_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    reference_number VARCHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    amount NUMERIC(19, 4) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, reference_number)
);
CREATE INDEX refunds_business_created_at_index ON refunds (business_id, created_at);

CREATE TABLE refund_items (
    id BIGSERIAL PRIMARY KEY,
    refund_id BIGINT NOT NULL REFERENCES refunds(id) ON DELETE CASCADE,
    sale_item_id BIGINT NOT NULL REFERENCES sale_items(id) ON DELETE RESTRICT,
    quantity INTEGER NOT NULL CHECK (quantity >= 0),
    unit_amount NUMERIC(19, 4) NOT NULL,
    line_amount NUMERIC(19, 4) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (refund_id, sale_item_id)
);

CREATE TABLE stock_transfers (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    from_warehouse_id BIGINT NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT,
    to_warehouse_id BIGINT NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    reference_number VARCHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    notes TEXT,
    shipped_at TIMESTAMP(0) WITHOUT TIME ZONE,
    completed_at TIMESTAMP(0) WITHOUT TIME ZONE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, reference_number)
);
CREATE INDEX stock_transfers_business_status_created_index ON stock_transfers (business_id, status, created_at);

CREATE TABLE stock_transfer_items (
    id BIGSERIAL PRIMARY KEY,
    stock_transfer_id BIGINT NOT NULL REFERENCES stock_transfers(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    quantity INTEGER NOT NULL CHECK (quantity >= 0),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (stock_transfer_id, product_id)
);

CREATE TABLE cash_registers (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(40) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, code)
);

CREATE TABLE cash_register_sessions (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    cash_register_id BIGINT NOT NULL REFERENCES cash_registers(id) ON DELETE RESTRICT,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    opening_balance NUMERIC(19, 4) NOT NULL DEFAULT 0,
    expected_cash NUMERIC(19, 4) NOT NULL DEFAULT 0,
    actual_cash NUMERIC(19, 4),
    difference NUMERIC(19, 4),
    opened_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    closed_at TIMESTAMP(0) WITHOUT TIME ZONE,
    closing_notes TEXT,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX cash_sessions_business_status_opened_index ON cash_register_sessions (business_id, status, opened_at);

CREATE TABLE cash_movements (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    cash_register_session_id BIGINT NOT NULL REFERENCES cash_register_sessions(id) ON DELETE CASCADE,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    type VARCHAR(24) NOT NULL,
    amount NUMERIC(19, 4) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX cash_movements_business_created_at_index ON cash_movements (business_id, created_at);

CREATE TABLE expense_categories (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    UNIQUE (business_id, name)
);

CREATE TABLE expenses (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    expense_category_id BIGINT REFERENCES expense_categories(id) ON DELETE SET NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    reference_number VARCHAR(64),
    description VARCHAR(255) NOT NULL,
    payment_method VARCHAR(32) NOT NULL,
    amount NUMERIC(19, 4) NOT NULL,
    incurred_on DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX expenses_business_incurred_on_index ON expenses (business_id, incurred_on);

CREATE TABLE audit_logs (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(160) NOT NULL,
    entity_id VARCHAR(80),
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX audit_logs_business_created_at_index ON audit_logs (business_id, created_at);
CREATE INDEX audit_logs_business_entity_index ON audit_logs (business_id, entity_type, entity_id);

CREATE TABLE webhooks (
    id BIGSERIAL PRIMARY KEY,
    business_id UUID NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
    url VARCHAR(2048) NOT NULL,
    secret_hash VARCHAR(255) NOT NULL,
    events JSON NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX webhooks_business_active_index ON webhooks (business_id, is_active);

CREATE TABLE webhook_deliveries (
    id BIGSERIAL PRIMARY KEY,
    webhook_id BIGINT NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event VARCHAR(100) NOT NULL,
    idempotency_key VARCHAR(120) NOT NULL UNIQUE,
    payload JSON NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    response_code SMALLINT CHECK (response_code >= 0),
    response_body TEXT,
    next_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE,
    delivered_at TIMESTAMP(0) WITHOUT TIME ZONE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX webhook_deliveries_webhook_status_next_index ON webhook_deliveries (webhook_id, status, next_attempt_at);

CREATE TABLE personal_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id BIGINT NOT NULL,
    name TEXT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT,
    last_used_at TIMESTAMP(0) WITHOUT TIME ZONE,
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE
);
CREATE INDEX personal_access_tokens_tokenable_index ON personal_access_tokens (tokenable_type, tokenable_id);
CREATE INDEX personal_access_tokens_expires_at_index ON personal_access_tokens (expires_at);

CREATE TABLE cache (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT NOT NULL,
    expiration BIGINT NOT NULL
);
CREATE INDEX cache_expiration_index ON cache (expiration);

CREATE TABLE cache_locks (
    key VARCHAR(255) PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    expiration BIGINT NOT NULL
);
CREATE INDEX cache_locks_expiration_index ON cache_locks (expiration);

CREATE TABLE jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL CHECK (attempts >= 0),
    reserved_at INTEGER CHECK (reserved_at >= 0),
    available_at INTEGER NOT NULL CHECK (available_at >= 0),
    created_at INTEGER NOT NULL CHECK (created_at >= 0)
);
CREATE INDEX jobs_queue_index ON jobs (queue);

CREATE TABLE job_batches (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INTEGER NOT NULL,
    pending_jobs INTEGER NOT NULL,
    failed_jobs INTEGER NOT NULL,
    failed_job_ids TEXT NOT NULL,
    options TEXT,
    cancelled_at INTEGER,
    created_at INTEGER NOT NULL,
    finished_at INTEGER
);

CREATE TABLE failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) NOT NULL UNIQUE,
    connection VARCHAR(255) NOT NULL,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX failed_jobs_connection_queue_failed_at_index ON failed_jobs (connection, queue, failed_at);

CREATE TABLE migrations (
    id SERIAL PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INTEGER NOT NULL
);

INSERT INTO migrations (migration, batch) VALUES
    ('0001_01_01_000000_create_users_table', 1),
    ('0001_01_01_000001_create_cache_table', 1),
    ('0001_01_01_000002_create_jobs_table', 1),
    ('2026_09_28_191542_create_personal_access_tokens_table', 1),
    ('2026_09_28_193350_create_businesses_table', 1),
    ('2026_09_28_201544_create_products_table', 1),
    ('2026_09_28_201546_create_inventory_stocks_table', 1),
    ('2026_09_28_201549_create_sales_table', 1),
    ('2026_09_28_201550_create_stock_movements_table', 1),
    ('2026_09_28_201551_create_sale_items_table', 1),
    ('2026_09_29_000001_add_commercial_pos_domain_tables', 1);

COMMIT;
