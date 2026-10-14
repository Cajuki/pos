<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_product_creation_enforces_the_active_subscription_limit(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $business->subscriptions()->create([
            'subscription_plan_id' => SubscriptionPlan::query()->where('code', 'starter')->value('id'),
            'status' => 'active',
            'starts_at' => now(),
        ]);
        $business->activeSubscription()->firstOrFail()->plan->update([
            'limits' => ['users' => 5, 'products' => 1, 'locations' => 1],
        ]);

        $this->postJson('/api/v1/products', [
            'sku' => 'LIMIT-001',
            'name' => 'First product',
            'unit_price' => 10,
        ])->assertCreated();

        $this->postJson('/api/v1/products', [
            'sku' => 'LIMIT-002',
            'name' => 'Over-limit product',
            'unit_price' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('products');

        $this->assertDatabaseCount('products', 1);
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
        $this->getJson('/api/v1/products/barcode/0123456789012')
            ->assertOk()
            ->assertJsonPath('data.product.id', $product['id'])
            ->assertJsonPath('data.tracked_barcode', null)
            ->assertJsonPath('data.status', 'product');

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 10,
            'reason' => 'Opening count',
        ])->assertCreated()->assertJsonPath('data.quantity_after', 10);

        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '50.00'])
            ->assertCreated();

        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'cash_received' => '30.00',
            'items' => [['product_id' => $product['id'], 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.total', '24.00')
            ->assertJsonPath('data.cash_received', '30.00')
            ->assertJsonPath('data.change_given', '6.00')
            ->assertJsonPath('data.items.0.line_total', '24.00')
            ->assertJsonPath('data.items.0.barcode', '0123456789012')
            ->json('data');

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale['id'],
            'product_id' => $product['id'],
            'barcode' => '0123456789012',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'cash_received' => '30.00', 'change_given' => '6.00']);
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

    public function test_checkout_idempotency_replays_the_original_sale_without_changing_stock_or_cash(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'IDEMPOTENT-001',
            'name' => 'Retry-safe item',
            'unit_price' => 25,
            'quantity_on_hand' => 5,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $payload = [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ];

        $firstSale = $this->withHeader('Idempotency-Key', 'checkout-2026-0001')
            ->postJson('/api/v1/sales', $payload)
            ->assertCreated()
            ->json('data');
        $replayedSale = $this->withHeader('Idempotency-Key', 'checkout-2026-0001')
            ->postJson('/api/v1/sales', $payload)
            ->assertOk()
            ->json('data');

        $this->assertSame($firstSale['id'], $replayedSale['id']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 4]);
        $this->assertDatabaseHas('cash_register_sessions', ['business_id' => $business->id, 'expected_cash' => '25.00']);

        $this->withHeader('Idempotency-Key', 'checkout-2026-0001')
            ->postJson('/api/v1/sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $product['id'], 'quantity' => 2]],
            ])
            ->assertConflict();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_sales_and_refunds_reconcile_against_the_selected_cash_register(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $defaultRegister = $this->getJson('/api/v1/cash-registers/current')->assertOk()->json('data.register');
        $sideRegister = $this->postJson('/api/v1/cash-registers', [
            'name' => 'Side Counter',
            'code' => 'side-01',
        ])->assertCreated()->assertJsonPath('data.register.code', 'SIDE-01')->json('data.register');
        $this->postJson('/api/v1/cash-registers/open', [
            'register_id' => $defaultRegister['id'],
            'opening_balance' => '10.00',
        ])->assertCreated();
        $sideSession = $this->postJson('/api/v1/cash-registers/open', [
            'register_id' => $sideRegister['id'],
            'opening_balance' => '20.00',
        ])->assertCreated()->json('data');

        $product = $this->postJson('/api/v1/products', [
            'sku' => 'TILL-001',
            'name' => 'Till test item',
            'unit_price' => 10,
            'quantity_on_hand' => 2,
        ])->assertCreated()->json('data');
        $sale = $this->postJson('/api/v1/sales', [
            'cash_register_id' => $sideRegister['id'],
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.cash_register_id', $sideRegister['id'])->json('data');

        $this->assertDatabaseHas('cash_register_sessions', ['cash_register_id' => $defaultRegister['id'], 'expected_cash' => '10.00']);
        $this->assertDatabaseHas('cash_register_sessions', ['cash_register_id' => $sideRegister['id'], 'expected_cash' => '30.00']);

        $this->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'reason' => 'Return at the side counter',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.amount', '10.00');
        $this->assertDatabaseHas('cash_register_sessions', ['cash_register_id' => $sideRegister['id'], 'expected_cash' => '20.00']);
        $this->assertDatabaseHas('cash_register_sessions', ['cash_register_id' => $defaultRegister['id'], 'expected_cash' => '10.00']);

        $this->postJson('/api/v1/cash-registers/close', [
            'register_id' => $sideRegister['id'],
            'actual_cash' => '20.00',
        ])->assertOk()->assertJsonPath('data.difference', '0.00');
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'cash_register.session_opened', 'entity_id' => (string) $sideSession['id']]);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'cash_register.session_closed', 'entity_id' => (string) $sideSession['id']]);

        $this->postJson('/api/v1/cash-registers/close', [
            'register_id' => $defaultRegister['id'],
            'actual_cash' => '9.99',
        ])->assertOk()->assertJsonPath('data.difference', '-0.01');
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

    public function test_cash_checkout_rejects_tender_below_total_and_calculates_exact_change(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'CHANGE-001',
            'name' => 'Change test item',
            'unit_price' => 25,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 2,
            'reason' => 'Opening count',
        ])->assertCreated();
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();

        $payload = [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ];

        $this->postJson('/api/v1/sales', [...$payload, 'cash_received' => '24.99'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cash_received');
        $this->assertDatabaseCount('sales', 0);

        $this->postJson('/api/v1/sales', [...$payload, 'cash_received' => '50.50'])
            ->assertCreated()
            ->assertJsonPath('data.total', '25.00')
            ->assertJsonPath('data.cash_received', '50.50')
            ->assertJsonPath('data.change_given', '25.50');
    }

    public function test_cash_refund_restores_stock_and_reconciles_the_open_register(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'RETURN-001',
            'name' => 'Returnable item',
            'unit_price' => 50,
            'quantity_on_hand' => 3,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 2]],
        ])->assertCreated()->json('data');

        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business, ['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum')->withHeader('X-Business-ID', $business->id)
            ->postJson("/api/v1/sales/{$sale['id']}/refunds", [
                'reason' => 'Customer returned one unopened item',
                'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
            ])->assertForbidden();

        $this->useBusinessContext($user, $business);
        $this->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'reason' => 'Customer returned one unopened item',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 3]],
        ])->assertUnprocessable()->assertJsonValidationErrors("items.{$sale['items'][0]['id']}.quantity");

        $refund = $this->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'reason' => 'Customer returned one unopened item',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.amount', '50.00')
            ->json('data');

        $this->assertDatabaseHas('inventory_stocks', [
            'business_id' => $business->id,
            'product_id' => $product['id'],
            'quantity_on_hand' => 2,
        ]);
        $this->assertDatabaseHas('cash_register_sessions', ['expected_cash' => '50.00']);
        $this->assertDatabaseHas('cash_movements', [
            'business_id' => $business->id,
            'type' => 'refund',
            'amount' => '50.00',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'sale.refunded',
            'entity_id' => (string) $sale['id'],
        ]);
        $this->assertDatabaseHas('refunds', ['id' => $refund['id'], 'sale_id' => $sale['id']]);

        $this->getJson('/api/v1/sales')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.refunded_quantity', 1);
        $this->getJson("/api/v1/sales/{$sale['id']}")
            ->assertOk()
            ->assertJsonPath('data.items.0.refunded_quantity', 1);
        $this->getJson('/api/v1/reports/summary?period=today')
            ->assertOk()
            ->assertJsonPath('data.today.revenue', 50)
            ->assertJsonPath('data.period.revenue', 50)
            ->assertJsonPath('data.sales_trend.0.total', 50);
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

    public function test_stock_movements_write_actor_attributed_audit_records(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'AUDIT-001',
            'name' => 'Audit test item',
            'unit_price' => 15,
            'quantity_on_hand' => 4,
        ])->assertCreated()->json('data');

        $movement = $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => -1,
            'reason' => 'Counted damaged unit',
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'action' => 'inventory.stock_movement_recorded',
            'entity_type' => StockMovement::class,
            'entity_id' => (string) $movement['id'],
        ]);
        $auditLog = DB::table('audit_logs')
            ->where('entity_type', StockMovement::class)
            ->where('entity_id', (string) $movement['id'])
            ->first();
        $this->assertSame(-1, json_decode($auditLog->new_values, true, flags: JSON_THROW_ON_ERROR)['quantity_change']);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 3]);
    }

    public function test_shared_barcode_product_stock_can_be_adjusted_up_and_down_by_quantity_change(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'JUICE-001',
            'barcode' => 'JUICE-SHARED-001',
            'name' => 'Juice',
            'unit_price' => 80,
            'quantity_on_hand' => 5,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 3,
            'reason' => 'Received extra stock',
        ])->assertCreated()
            ->assertJsonPath('data.quantity_before', 5)
            ->assertJsonPath('data.quantity_after', 8);

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => -2,
            'reason' => 'Damaged stock',
        ])->assertCreated()
            ->assertJsonPath('data.quantity_before', 8)
            ->assertJsonPath('data.quantity_after', 6);

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 0,
            'reason' => 'No change',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_change');

        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 6]);
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
            'barcode_tracking_enabled' => true,
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

    public function test_same_category_products_with_different_barcodes_remain_separate_in_purchases_and_sales(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);

        $tea = $this->postJson('/api/v1/products', [
            'sku' => 'TEA-001',
            'barcode' => 'TEA-UPC-001',
            'name' => 'Black Tea',
            'category' => 'Beverages',
            'unit_price' => 120,
        ])->assertCreated()->json('data');
        $coffee = $this->postJson('/api/v1/products', [
            'sku' => 'COFFEE-001',
            'barcode' => 'COFFEE-UPC-001',
            'name' => 'Ground Coffee',
            'category' => 'Beverages',
            'unit_price' => 250,
        ])->assertCreated()->json('data');

        $purchase = $this->postJson('/api/v1/purchases', [
            'items' => [
                ['product_id' => $tea['id'], 'quantity' => 5, 'unit_cost' => 70],
                ['product_id' => $coffee['id'], 'quantity' => 3, 'unit_cost' => 160],
            ],
        ])->assertCreated()
            ->assertJsonCount(2, 'data.items')
            ->json('data');

        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $tea['id'], 'quantity_on_hand' => 5]);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $coffee['id'], 'quantity_on_hand' => 3]);
        $this->getJson('/api/v1/products/barcode/TEA-UPC-001')
            ->assertOk()
            ->assertJsonPath('data.product.id', $tea['id']);
        $this->getJson('/api/v1/products/barcode/COFFEE-UPC-001')
            ->assertOk()
            ->assertJsonPath('data.product.id', $coffee['id']);

        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $tea['id'], 'quantity' => 1],
                ['product_id' => $coffee['id'], 'quantity' => 1],
            ],
        ])->assertCreated()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product_id', $tea['id'])
            ->assertJsonPath('data.items.1.product_id', $coffee['id'])
            ->assertJsonPath('data.items.0.product_name', 'Black Tea')
            ->assertJsonPath('data.items.1.product_name', 'Ground Coffee')
            ->json('data');

        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale['id'], 'product_id' => $tea['id'], 'quantity' => 1]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale['id'], 'product_id' => $coffee['id'], 'quantity' => 1]);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $tea['id'], 'quantity_on_hand' => 4]);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $coffee['id'], 'quantity_on_hand' => 2]);
        $this->assertDatabaseCount('purchase_items', 2);
    }

    public function test_individually_tracked_products_are_received_through_purchase_unit_barcodes(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'PHONE-001',
            'name' => 'Mobile phone',
            'category' => 'Electronics',
            'unit_price' => 25000,
            'barcode_tracking_enabled' => true,
            'quantity_on_hand' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_on_hand');

        $product = $this->postJson('/api/v1/products', [
            'sku' => 'PHONE-001',
            'name' => 'Mobile phone',
            'category' => 'Electronics',
            'unit_price' => 25000,
            'barcode_tracking_enabled' => true,
        ])->assertCreated()
            ->assertJsonPath('data.barcode_tracking_enabled', true)
            ->assertJsonPath('data.quantity_on_hand', 0)
            ->json('data');
        $sharedBarcodeProduct = $this->postJson('/api/v1/products', [
            'sku' => 'CASE-001',
            'barcode' => 'CASE-SHARED-001',
            'name' => 'Phone case',
            'unit_price' => 500,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/barcodes/receive', [
            'product_id' => $sharedBarcodeProduct['id'],
            'barcode' => 'CASE-UNIT-001',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
        $this->assertDatabaseHas('products', ['id' => $sharedBarcodeProduct['id'], 'barcode_tracking_enabled' => false]);

        $this->postJson('/api/v1/purchases', [
            'items' => [['product_id' => $product['id'], 'quantity' => 2, 'unit_cost' => 19000]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('purchase_orders', 0);

        $purchase = $this->postJson('/api/v1/purchases', [
            'items' => [[
                'product_id' => $product['id'],
                'quantity' => 2,
                'unit_cost' => 19000,
                'barcodes' => ['PHONE-SERIAL-001', 'PHONE-SERIAL-002'],
            ]],
        ])->assertCreated()
            ->assertJsonCount(2, 'data.items.0.product_barcodes')
            ->json('data');

        $this->assertDatabaseHas('product_barcodes', [
            'business_id' => $business->id,
            'product_id' => $product['id'],
            'purchase_item_id' => $purchase['items'][0]['id'],
            'barcode' => 'PHONE-SERIAL-001',
            'status' => 'in_stock',
        ]);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 2]);
        $this->getJson('/api/v1/products/barcode/PHONE-SERIAL-001')
            ->assertOk()
            ->assertJsonPath('data.product.id', $product['id'])
            ->assertJsonPath('data.tracked_barcode', 'PHONE-SERIAL-001');
    }

    public function test_serialized_cash_refund_requires_and_releases_the_returned_unit_barcode(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'SERIAL-RETURN-001',
            'name' => 'Serialized device',
            'unit_price' => 100,
            'barcode_tracking_enabled' => true,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/barcodes/receive', [
            'product_id' => $product['id'],
            'barcode' => 'RETURN-SERIAL-01',
        ])->assertCreated();
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'barcodes' => ['RETURN-SERIAL-01']]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'reason' => 'Device returned',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'reason' => 'Device returned',
            'items' => [[
                'sale_item_id' => $sale['items'][0]['id'],
                'quantity' => 1,
                'barcodes' => ['RETURN-SERIAL-01'],
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('product_barcodes', [
            'business_id' => $business->id,
            'barcode' => 'RETURN-SERIAL-01',
            'status' => 'in_stock',
            'sale_item_id' => null,
        ]);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 1]);
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

    public function test_service_products_are_sold_without_inventory_or_purchase_stock_movements(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $service = $this->postJson('/api/v1/products', [
            'sku' => 'HAIRCUT-001',
            'name' => 'Haircut',
            'unit_price' => 500,
            'product_type' => 'service',
            'service_duration_minutes' => 45,
            'quantity_on_hand' => 0,
        ])->assertCreated()
            ->assertJsonPath('data.product_type', 'service')
            ->assertJsonPath('data.service_duration_minutes', 45)
            ->json('data');

        $this->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $service['id'],
            'quantity_change' => 1,
            'reason' => 'Not stock',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/purchases', [
            'items' => [['product_id' => $service['id'], 'quantity' => 1, 'unit_cost' => 100]],
        ])->assertUnprocessable();

        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $service['id'], 'quantity' => 1, 'performed_by_user_id' => $user->id]],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.performed_by_user_id', $user->id)
            ->json('data');

        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale['id'], 'performed_by_user_id' => $user->id]);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $service['id'], 'quantity_on_hand' => 0]);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_restaurant_orders_reserve_stock_and_settle_at_the_saved_price(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'SOUP-001',
            'name' => 'Soup',
            'unit_price' => 150,
            'quantity_on_hand' => 5,
        ])->assertCreated()->json('data');

        $order = $this->postJson('/api/v1/restaurant-orders', [
            'order_type' => 'dine_in',
            'table_name' => 'Table 4',
            'items' => [['product_id' => $product['id'], 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '150.00')
            ->json('data');
        $this->assertDatabaseHas('inventory_stocks', [
            'product_id' => $product['id'],
            'quantity_on_hand' => 5,
            'quantity_reserved' => 2,
        ]);

        Product::query()->whereKey($product['id'])->update(['unit_price' => 200]);

        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $sale = $this->postJson('/api/v1/sales', [
            'restaurant_order_id' => $order['id'],
            'payment_method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '150.00')
            ->assertJsonPath('data.items.0.line_total', '300.00')
            ->assertJsonPath('data.total', '300.00')
            ->json('data');

        $this->assertDatabaseHas('restaurant_orders', ['id' => $order['id'], 'status' => 'paid', 'sale_id' => $sale['id']]);
        $this->assertDatabaseHas('inventory_stocks', [
            'product_id' => $product['id'],
            'quantity_on_hand' => 3,
            'quantity_reserved' => 0,
        ]);
        $this->postJson('/api/v1/sales', [
            'restaurant_order_id' => $order['id'],
            'payment_method' => 'cash',
        ])->assertUnprocessable();
    }

    public function test_restaurant_cancellation_releases_stock_and_appointment_conflicts_are_rejected(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'CAKE-001',
            'name' => 'Cake',
            'unit_price' => 80,
            'quantity_on_hand' => 4,
        ])->assertCreated()->json('data');
        $order = $this->postJson('/api/v1/restaurant-orders', [
            'order_type' => 'takeaway',
            'items' => [['product_id' => $product['id'], 'quantity' => 2]],
        ])->assertCreated()->json('data');
        $this->patchJson('/api/v1/restaurant-orders/'.$order['id'].'/status', ['status' => 'cancelled'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 4, 'quantity_reserved' => 0]);

        $service = $this->postJson('/api/v1/products', [
            'sku' => 'NAIL-001',
            'name' => 'Nail service',
            'unit_price' => 300,
            'product_type' => 'service',
            'service_duration_minutes' => 30,
        ])->assertCreated()->json('data');
        $startsAt = now()->addDay()->startOfHour();
        $appointmentPayload = [
            'service_product_id' => $service['id'],
            'staff_user_id' => $user->id,
            'starts_at' => $startsAt->toIso8601String(),
        ];
        $appointment = $this->postJson('/api/v1/appointments', $appointmentPayload)
            ->assertCreated()->assertJsonPath('data.status', 'booked')->json('data');
        $this->postJson('/api/v1/appointments', [
            ...$appointmentPayload,
            'starts_at' => $startsAt->copy()->addMinutes(15)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_user_id');

        $this->patchJson('/api/v1/appointments/'.$appointment['id'].'/status', ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $sale = $this->postJson('/api/v1/sales', [
            'appointment_id' => $appointment['id'],
            'payment_method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('data.items.0.performed_by_user_id', $user->id)
            ->json('data');
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment['id'],
            'status' => 'completed',
            'sale_id' => $sale['id'],
        ]);
    }

    public function test_pharmacy_checkout_uses_unexpired_batches_in_first_expiry_order(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $business->update([
            'industry' => 'pharmacy',
            'settings' => [...$business->posSettings(), 'enabled_modules' => ['pharmacy']],
        ]);
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'MED-001',
            'name' => 'Pain relief tablets',
            'unit_price' => 120,
            'batch_tracking_enabled' => true,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/inventory/batches/receive', [
            'product_id' => $product['id'],
            'lot_number' => 'LOT-EARLY',
            'expires_on' => now()->addDays(5)->toDateString(),
            'quantity' => 2,
            'unit_cost' => '45.00',
        ])->assertCreated();
        $this->postJson('/api/v1/inventory/batches/receive', [
            'product_id' => $product['id'],
            'lot_number' => 'LOT-LATE',
            'expires_on' => now()->addDays(15)->toDateString(),
            'quantity' => 3,
            'unit_cost' => '50.00',
        ])->assertCreated();
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();

        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 3]],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.batch_allocations.0.lot_number', 'LOT-EARLY')
            ->assertJsonPath('data.items.0.batch_allocations.0.quantity', 2)
            ->assertJsonPath('data.items.0.batch_allocations.1.lot_number', 'LOT-LATE')
            ->assertJsonPath('data.items.0.batch_allocations.1.quantity', 1)
            ->json('data');

        $this->assertDatabaseHas('inventory_batches', ['id' => 1, 'quantity_remaining' => 0]);
        $this->assertDatabaseHas('inventory_batches', ['id' => 2, 'quantity_remaining' => 2]);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 2]);
        $this->assertDatabaseHas('sale_item_batches', ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]);

        $this->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'reason' => 'Returned unopened medicine',
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 3]],
        ])->assertCreated()->assertJsonPath('data.amount', '360.00');
        $this->assertDatabaseHas('inventory_batches', ['id' => 1, 'quantity_remaining' => 2]);
        $this->assertDatabaseHas('inventory_batches', ['id' => 2, 'quantity_remaining' => 3]);
        $this->assertDatabaseHas('inventory_stocks', ['product_id' => $product['id'], 'quantity_on_hand' => 5]);
    }

    public function test_pharmacy_inventory_reports_expired_and_upcoming_expiry_units(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $business->update([
            'industry' => 'pharmacy',
            'settings' => [...$business->posSettings(), 'enabled_modules' => ['pharmacy']],
        ]);
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'MED-EXPIRY-001',
            'name' => 'Expiry test medicine',
            'unit_price' => 120,
            'batch_tracking_enabled' => true,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/inventory/batches/receive', [
            'product_id' => $product['id'],
            'lot_number' => 'LOT-EXPIRED',
            'expires_on' => now()->addDays(5)->toDateString(),
            'quantity' => 2,
        ])->assertCreated();
        $this->postJson('/api/v1/inventory/batches/receive', [
            'product_id' => $product['id'],
            'lot_number' => 'LOT-SOON',
            'expires_on' => now()->addDays(10)->toDateString(),
            'quantity' => 3,
        ])->assertCreated();
        $this->postJson('/api/v1/inventory/batches/receive', [
            'product_id' => $product['id'],
            'lot_number' => 'LOT-LATER',
            'expires_on' => now()->addDays(31)->toDateString(),
            'quantity' => 4,
        ])->assertCreated();
        DB::table('inventory_batches')
            ->where('business_id', $business->id)
            ->where('lot_number', 'LOT-EXPIRED')
            ->update(['expires_on' => now()->subDay()->toDateString()]);

        $this->getJson('/api/v1/inventory/batches')
            ->assertOk()
            ->assertJsonPath('meta.expiry_alerts.expired_units', 2)
            ->assertJsonPath('meta.expiry_alerts.expiring_units', 3);
    }

    public function test_pharmacy_medicine_details_are_tenant_configured_and_prescription_sales_are_blocked(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $business->update([
            'industry' => 'pharmacy',
            'settings' => [...$business->posSettings(), 'enabled_modules' => ['pharmacy']],
        ]);
        $this->useBusinessContext($user, $business);

        $product = $this->postJson('/api/v1/products', [
            'sku' => 'MED-RX-001',
            'name' => 'Amoxicillin 500 mg capsules',
            'unit_price' => 850,
            'batch_tracking_enabled' => true,
            'brand_name' => 'Example brand',
            'generic_name' => 'Amoxicillin',
            'active_ingredient' => 'Amoxicillin trihydrate',
            'strength' => '500 mg',
            'dosage_form' => 'capsule',
            'pack_size' => '21 capsules',
            'unit_of_sale' => 'capsule',
            'manufacturer' => 'Example Pharma',
            'registration_number' => 'REG-001',
            'medicine_classification' => 'prescription',
            'prescription_required' => true,
        ])->assertCreated()
            ->assertJsonPath('data.generic_name', 'Amoxicillin')
            ->assertJsonPath('data.dosage_form', 'capsule')
            ->assertJsonPath('data.prescription_required', true)
            ->json('data');

        $this->postJson('/api/v1/inventory/batches/receive', [
            'product_id' => $product['id'],
            'lot_number' => 'RX-LOT-001',
            'expires_on' => now()->addMonths(6)->toDateString(),
            'quantity' => 21,
        ])->assertCreated();
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->assertDatabaseHas('inventory_batches', ['product_id' => $product['id'], 'quantity_remaining' => 21]);
        $this->assertDatabaseCount('sales', 0);

        $business->update([
            'industry' => 'retail',
            'settings' => [...$business->posSettings(), 'enabled_modules' => []],
        ]);
        $this->postJson('/api/v1/products', [
            'sku' => 'RETAIL-MED-001',
            'name' => 'Misconfigured medicine',
            'unit_price' => 10,
            'generic_name' => 'Example',
        ])->assertUnprocessable()->assertJsonValidationErrors('medicine_classification');
    }

    public function test_wholesale_quantity_tiers_are_applied_by_the_server_at_checkout(): void
    {
        [$user, $business] = $this->createBusinessContext();
        $business->update([
            'industry' => 'wholesale',
            'settings' => [...$business->posSettings(), 'enabled_modules' => ['variants', 'wholesale']],
        ]);
        $this->useBusinessContext($user, $business);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'BULK-001',
            'name' => 'Wholesale rice bag',
            'unit_price' => 100,
            'variant_attributes' => ['pack' => '25 kg'],
            'bulk_pricing' => [
                ['minimum_quantity' => 10, 'unit_price' => 90],
                ['minimum_quantity' => 25, 'unit_price' => 80],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.variant_attributes.pack', '25 kg')
            ->json('data');
        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product['id'],
            'quantity_change' => 30,
            'reason' => 'Opening count',
        ])->assertCreated();
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();

        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product['id'], 'quantity' => 12]],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '90.00')
            ->assertJsonPath('data.items.0.line_total', '1080.00');

        $this->postJson('/api/v1/products', [
            'sku' => 'BAD-BULK-001',
            'name' => 'Invalid tier',
            'unit_price' => 100,
            'bulk_pricing' => [
                ['minimum_quantity' => 10, 'unit_price' => 90],
                ['minimum_quantity' => 5, 'unit_price' => 80],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('bulk_pricing');
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
