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
