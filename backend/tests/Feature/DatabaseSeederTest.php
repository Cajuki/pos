<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockMovement;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_linked_stock_and_is_repeatable(): void
    {
        $this->seed(DatabaseSeeder::class);

        $productCount = Product::query()->count();
        $stockCount = InventoryStock::query()->count();
        $movementCount = StockMovement::query()->count();
        $purchaseCount = DB::table('purchase_orders')->count();

        $this->assertGreaterThan(0, $productCount);
        $this->assertSame($productCount, $stockCount);
        $this->assertGreaterThan(0, $movementCount);
        $this->assertGreaterThan(0, $purchaseCount);
        $this->assertSame(0, StockMovement::query()->whereDoesntHave('inventoryStock')->count());

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($productCount, Product::query()->count());
        $this->assertSame($stockCount, InventoryStock::query()->count());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($purchaseCount, DB::table('purchase_orders')->count());
    }
}
