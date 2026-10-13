<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_reservation_is_branch_bound_and_released_to_cleaning_on_cancel(): void
    {
        [$business, $user, $branch, $register] = $this->restaurantContext();
        $table = $this->postJson('/api/v1/restaurant/tables', [
            'branch_id' => $branch->id,
            'name' => 'Table 4',
            'area' => 'Patio',
            'seating_capacity' => 4,
        ])->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'DISH-4',
            'name' => 'Pilau',
            'unit_price' => 650,
            'quantity_on_hand' => 5,
        ])->assertCreated()->json('data');

        $order = $this->postJson('/api/v1/restaurant-orders', [
            'cash_register_id' => $register,
            'order_type' => 'dine_in',
            'restaurant_table_id' => $table['id'],
            'items' => [['product_id' => $product['id'], 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.table_name', 'Table 4')->json('data');

        $this->assertDatabaseHas('restaurant_tables', ['id' => $table['id'], 'status' => 'occupied']);
        $this->postJson('/api/v1/restaurant-orders', [
            'cash_register_id' => $register,
            'order_type' => 'dine_in',
            'restaurant_table_id' => $table['id'],
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');

        $this->patchJson('/api/v1/restaurant-orders/'.$order['id'].'/status', ['status' => 'cancelled'])->assertOk();
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table['id'], 'status' => 'cleaning']);
        $this->patchJson('/api/v1/restaurant/tables/'.$table['id'], ['status' => 'available'])->assertOk();
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table['id'], 'status' => 'available']);
    }

    public function test_settling_table_order_marks_the_table_cleaning(): void
    {
        [$business, $user, $branch, $register] = $this->restaurantContext();
        $table = $this->postJson('/api/v1/restaurant/tables', [
            'branch_id' => $branch->id,
            'name' => 'Table 2',
        ])->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'SOUP-2',
            'name' => 'Soup',
            'unit_price' => 300,
            'quantity_on_hand' => 3,
        ])->assertCreated()->json('data');
        $order = $this->postJson('/api/v1/restaurant-orders', [
            'cash_register_id' => $register,
            'order_type' => 'dine_in',
            'restaurant_table_id' => $table['id'],
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/sales', [
            'restaurant_order_id' => $order['id'],
            'payment_method' => 'cash',
            'cash_register_id' => $register,
            'cash_received' => 300,
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseHas('restaurant_tables', ['id' => $table['id'], 'status' => 'cleaning']);
    }

    public function test_table_cannot_be_selected_from_a_different_till_branch(): void
    {
        [$business, $user, $branch, $register] = $this->restaurantContext();
        $otherBranch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Second Branch',
            'code' => 'SECOND',
            'is_active' => true,
        ]);
        $otherTill = $this->postJson('/api/v1/cash-registers', [
            'branch_id' => $otherBranch->id,
            'name' => 'Second Till',
            'code' => 'SECOND-1',
        ])->assertCreated()->json('data.register.id');
        $table = $this->postJson('/api/v1/restaurant/tables', [
            'branch_id' => $branch->id,
            'name' => 'Branch One Table',
        ])->assertCreated()->json('data');
        $product = Product::create([
            'business_id' => $business->id,
            'sku' => 'TEST-DISH',
            'name' => 'Test Dish',
            'unit_price' => 100,
            'cost_price' => 50,
        ]);
        InventoryStock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 2,
            'quantity_reserved' => 0,
        ]);

        $this->postJson('/api/v1/restaurant-orders', [
            'cash_register_id' => $otherTill,
            'order_type' => 'dine_in',
            'restaurant_table_id' => $table['id'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
    }

    private function restaurantContext(): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'Restaurant Test']);
        $user->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $branch = $business->branches()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]);
        $register = $this->postJson('/api/v1/cash-registers', [
            'branch_id' => $branch->id,
            'name' => 'Main Till',
            'code' => 'MAIN-1',
        ])->assertCreated()->json('data.register.id');
        $this->postJson('/api/v1/cash-registers/open', [
            'register_id' => $register,
            'opening_balance' => 1000,
        ])->assertCreated();

        return [$business, $user, $branch, $register];
    }
}
