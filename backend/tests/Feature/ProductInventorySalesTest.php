<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductInventorySalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_creation_sets_initial_stock_and_exposes_reorder_level(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);

        $product = $this->postJson('/api/v1/products', [
            'sku' => 'RICE-001',
            'name' => 'Rice',
            'unit_price' => 20,
            'quantity_on_hand' => 12,
            'reorder_level' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.quantity_on_hand', 12)
            ->assertJsonPath('data.reorder_level', 4)
            ->json('data');

        $this->getJson('/api/v1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_on_hand', 12)
            ->assertJsonPath('data.0.reorder_level', 4)
            ->assertJsonPath('data.0.is_low_stock', false);

        $this->assertDatabaseHas('inventory_stocks', [
            'business_id' => $business->id,
            'product_id' => $product['id'],
            'quantity_on_hand' => 12,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $business->id,
            'product_id' => $product['id'],
            'type' => 'adjustment',
            'quantity_change' => 12,
            'quantity_before' => 0,
            'quantity_after' => 12,
            'reason' => 'Opening stock',
        ]);
    }

    public function test_product_requires_integer_price_and_character_based_sku(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);

        $this->postJson('/api/v1/products', [
            'sku' => 'RICE#001',
            'name' => 'Rice',
            'unit_price' => '20.00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'unit_price']);

        $this->postJson('/api/v1/products', [
            'sku' => 'RICE-001',
            'name' => 'Rice',
            'unit_price' => 20,
        ])->assertCreated();
    }

    public function test_product_stock_and_checkout_flow_is_transactional(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);

        $product = $this->postJson('/api/v1/products', [
            'sku' => 'TEA-001',
            'barcode' => '0123456789012',
            'name' => 'Black Tea',
            'unit_price' => 12,
            'cost_price' => 8,
            'reorder_level' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.quantity_on_hand', 0)
            ->assertJsonPath('data.barcode', '0123456789012')
            ->json('data');

        $this->getJson('/api/v1/products?search=0123456789012')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product['id']);

        $this->getJson('/api/v1/products?barcode=0123456789012&active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.barcode', '0123456789012');

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 10,
            'reason' => 'Opening count',
        ])->assertCreated()->assertJsonPath('data.quantity_after', 10);

        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '50.00'])
            ->assertCreated();

        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.total', '24.00')
            ->assertJsonPath('data.items.0.line_total', '24.00')
            ->json('data');

        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 8]);
        $this->assertDatabaseHas('stock_movements', ['sale_id' => $sale['id'], 'quantity_change' => -2]);
        $this->assertDatabaseHas('cash_register_sessions', [
            'business_id' => $business->id,
            'expected_cash' => '74.00',
        ]);

        $this->postJson('/api/v1/sales', [
            'payment_method' => 'mobile_money',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 9]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 8]);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_checkout_requires_an_open_register_and_reconciles_only_cash_sales(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'MILK-001',
            'name' => 'Milk',
            'unit_price' => 10,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 3,
            'reason' => 'Opening count',
        ])->assertCreated();

        $salePayload = [
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ];
        $this->postJson('/api/v1/sales', [...$salePayload, 'payment_method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cash_register');
        $this->assertDatabaseCount('sales', 0);

        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '50.00'])
            ->assertCreated();
        $this->postJson('/api/v1/sales', [...$salePayload, 'payment_method' => 'mpesa'])->assertCreated();
        $this->assertDatabaseHas('cash_register_sessions', ['expected_cash' => '50.00']);

        $this->postJson('/api/v1/sales', [...$salePayload, 'payment_method' => 'cash'])->assertCreated();
        $this->assertDatabaseHas('cash_register_sessions', ['expected_cash' => '60.00']);

        $this->postJson('/api/v1/cash-registers/close', ['actual_cash' => '60.00'])
            ->assertOk()
            ->assertJsonPath('data.difference', '0.00');
        $this->postJson('/api/v1/sales', [...$salePayload, 'payment_method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cash_register');
        $this->assertDatabaseCount('sales', 2);
    }

    public function test_checkout_applies_business_tax_settings_and_rejects_disabled_tenders(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $business->update(['settings' => [
            ...$business->posSettings(),
            'tax_enabled' => true,
            'tax_rate' => '10.00',
            'tax_inclusive' => false,
            'payment_methods' => ['cash'],
            'default_payment_method' => 'cash',
        ]]);
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'TAX-001',
            'name' => 'Taxable item',
            'unit_price' => 100,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 2,
            'reason' => 'Opening count',
        ])->assertCreated();
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();

        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.tax_amount', '10.00')
            ->assertJsonPath('data.tax_rate', '10.00')
            ->assertJsonPath('data.total', '110.00');

        $business->update(['settings' => [
            ...$business->posSettings(),
            'tax_inclusive' => true,
        ]]);
        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.tax_amount', '9.09')
            ->assertJsonPath('data.total', '100.00');

        $this->postJson('/api/v1/sales', [
            'payment_method' => 'mpesa',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseHas('cash_register_sessions', ['expected_cash' => '210.00']);
        $this->assertDatabaseCount('sales', 2);
    }

    public function test_sale_can_be_linked_to_a_business_customer_and_apply_configured_discount(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $business->update(['settings' => [
            ...$business->posSettings(),
            'discount_enabled' => true,
            'max_discount_percent' => '15.00',
        ]]);
        $this->useBusinessContext($user, $business);
        $customer = $this->postJson('/api/v1/customers', [
            'name' => 'Asha Customer',
            'phone' => '+254700000001',
        ])->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'DISC-001',
            'name' => 'Discounted item',
            'unit_price' => 200,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 2,
            'reason' => 'Opening count',
        ])->assertCreated();
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();

        $this->postJson('/api/v1/sales', [
            'customer_id' => $customer['id'],
            'discount_percent' => 15,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.customer_id', $customer['id'])
            ->assertJsonPath('data.customer.name', 'Asha Customer')
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.discount_amount', '30.00')
            ->assertJsonPath('data.total', '170.00');

        $this->postJson('/api/v1/sales', [
            'customer_id' => $customer['id'],
            'discount_percent' => 15.01,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('discount_percent');
        $this->assertDatabaseHas('cash_register_sessions', ['expected_cash' => '170.00']);
    }

    public function test_products_and_sales_are_scoped_to_business_membership(): void
    {
        [$owner, $business] = $this->createBusinessContext();
        $this->useBusinessContext($owner, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'COFFEE-001',
            'name' => 'Coffee',
            'unit_price' => 5,
        ])->assertCreated()->json('data');

        $otherUser = User::factory()->create();
        $otherBusiness = Business::create(['name' => 'Other Store']);
        $otherUser->businesses()->attach($otherBusiness, ['role' => 'owner']);
        $this->useBusinessContext($otherUser, $otherBusiness);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products/'.$product['id'])->assertNotFound();
        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertNotFound();
    }

    public function test_stock_adjustment_cannot_make_inventory_negative(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'SOAP-001',
            'name' => 'Soap',
            'unit_price' => 10,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => -1,
            'reason' => 'Incorrect count',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 0]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_individually_barcoded_stock_is_received_scanned_and_consumed_once(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'MANDAZI-001',
            'name' => 'Mandazi',
            'category' => 'Bakery',
            'unit_price' => 10,
        ])->assertCreated()->json('data');

        $this->getJson('/api/v1/products/barcode/UNIT-0001')
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->postJson('/api/v1/inventory/barcodes/receive', [
            'product_id' => $product['id'],
            'barcode' => 'UNIT-0001',
        ])->assertCreated()
            ->assertJsonPath('data.quantity_on_hand', 1);
        $this->postJson('/api/v1/inventory/barcodes/receive', [
            'product_id' => $product['id'],
            'barcode' => 'UNIT-0002',
        ])->assertCreated()
            ->assertJsonPath('data.quantity_on_hand', 2);

        $this->getJson('/api/v1/products/barcode/UNIT-0001')
            ->assertOk()
            ->assertJsonPath('data.product.id', $product['id'])
            ->assertJsonPath('data.tracked_barcode', 'UNIT-0001')
            ->assertJsonPath('data.status', 'in_stock');
        $this->getJson('/api/v1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_on_hand', 2)
            ->assertJsonPath('data.0.barcode_tracking_enabled', true);

        $this->postJson('/api/v1/inventory/barcodes/receive', [
            'product_id' => $product['id'],
            'barcode' => 'UNIT-0001',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('barcode');
        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 1,
            'reason' => 'Must use barcode intake',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');

        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.barcodes');
        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'barcodes' => ['UNIT-0001']]],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.barcodes.0', 'UNIT-0001')
            ->json('data');

        $this->assertDatabaseHas('product_barcodes', [
            'business_id' => $business->id,
            'product_id' => $product['id'],
            'sale_item_id' => $sale['items'][0]['id'] ?? null,
            'barcode' => 'UNIT-0001',
            'status' => 'sold',
        ]);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 1]);
        $this->getJson('/api/v1/products/barcode/UNIT-0001')
            ->assertOk()
            ->assertJsonPath('data.tracked_barcode', null)
            ->assertJsonPath('data.status', 'sold');
        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'barcodes' => ['UNIT-0001']]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.barcodes');
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_purchase_orders_reject_products_and_suppliers_from_another_business(): void
    {
        [$owner, $business] = $this->createBusinessContext();
        [$otherOwner, $otherBusiness] = $this->createBusinessContext();
        $this->useBusinessContext($otherOwner, $otherBusiness);

        $product = $this->postJson('/api/v1/products', [
            'sku' => 'OTHER-001',
            'name' => 'Other business product',
            'unit_price' => 12,
        ])->assertCreated()->json('data');
        $supplier = $this->postJson('/api/v1/suppliers', [
            'name' => 'Other business supplier',
        ])->assertCreated()->json('data');

        $this->useBusinessContext($owner, $business);
        $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplier['id'],
            'items' => [[
                'product_id' => $product['id'],
                'quantity' => 2,
                'unit_cost' => 5,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id', 'items.0.product_id']);

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    private function createBusinessContext(): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'Retail Store']);
        $user->businesses()->attach($business, ['role' => 'owner']);

        return [$user, $business];
    }

    private function useBusinessContext(User $user, Business $business): void
    {
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-ID', $business->id);
    }
}
