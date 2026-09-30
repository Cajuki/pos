<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductInventorySalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_stock_and_checkout_flow_is_transactional(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);

        $product = $this->postJson('/api/v1/products', [
            'sku' => 'TEA-001',
            'barcode' => '0123456789012',
            'name' => 'Black Tea',
            'unit_price' => '12.50',
            'cost_price' => '8.25',
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

        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.total', '25.00')
            ->assertJsonPath('data.items.0.line_total', '25.00')
            ->json('data');

        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 8]);
        $this->assertDatabaseHas('stock_movements', ['sale_id' => $sale['id'], 'quantity_change' => -2]);

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

    public function test_products_and_sales_are_scoped_to_business_membership(): void
    {
        [$owner, $business] = $this->createBusinessContext();
        $this->useBusinessContext($owner, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'COFFEE-001',
            'name' => 'Coffee',
            'unit_price' => '5.00',
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
            'unit_price' => '10.00',
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
            'unit_price' => '12.00',
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
