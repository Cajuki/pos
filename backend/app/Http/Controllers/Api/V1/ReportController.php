<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $period = $request->query('period', '7days');

        $now = now();
        $startDate = match ($period) {
            'today' => $now->copy()->startOfDay(),
            '30days' => $now->copy()->subDays(30)->startOfDay(),
            '3months' => $now->copy()->subMonths(3)->startOfDay(),
            '12months' => $now->copy()->subYear()->startOfDay(),
            default => $now->copy()->subDays(7)->startOfDay(),
        };

        // Today's metrics
        $todayStart = $now->copy()->startOfDay();
        $todaySales = Sale::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $todayStart)
            ->get();

        $todayRevenue = $todaySales->sum('total');
        $todayTransactions = $todaySales->count();

        // Total sales in period
        $periodSales = Sale::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $startDate)
            ->get();

        $periodRevenue = $periodSales->sum('total');
        $periodTransactions = $periodSales->count();

        // Low stock count
        $lowStockCount = InventoryStock::query()
            ->where('inventory_stocks.business_id', $business->id)
            ->join('products', 'products.id', '=', 'inventory_stocks.product_id')
            ->whereColumn('inventory_stocks.quantity_on_hand', '<=', 'products.reorder_level')
            ->count();

        // Total products & inventory valuation
        $totalProducts = Product::query()->where('business_id', $business->id)->count();
        $stocks = InventoryStock::query()
            ->where('inventory_stocks.business_id', $business->id)
            ->join('products', 'products.id', '=', 'inventory_stocks.product_id')
            ->select('inventory_stocks.quantity_on_hand', 'products.unit_price', 'products.cost_price')
            ->get();

        $inventoryValue = $stocks->sum(fn ($s) => $s->quantity_on_hand * (float) $s->unit_price);
        $totalUnits = $stocks->sum('quantity_on_hand');

        // Payment breakdown
        $paymentBreakdown = Sale::query()
            ->where('business_id', $business->id)
            ->select('payment_method', DB::raw('count(*) as count'), DB::raw('sum(total) as total'))
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ]);

        // Sales over time (daily buckets)
        $salesOverTime = Sale::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $startDate)
            ->select(DB::raw("to_char(created_at, 'YYYY-MM-DD') as day"), DB::raw('sum(total) as total'), DB::raw('count(*) as count'))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->day,
                'total' => (float) $r->total,
                'transactions' => (int) $r->count,
            ]);

        // Top products
        $topProducts = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.business_id', $business->id)
            ->select('sale_items.product_name', 'sale_items.sku', DB::raw('sum(sale_items.quantity) as units_sold'), DB::raw('sum(sale_items.line_total) as revenue'))
            ->groupBy('sale_items.product_name', 'sale_items.sku')
            ->orderByDesc('units_sold')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'today' => [
                    'revenue' => (float) $todayRevenue,
                    'transactions' => $todayTransactions,
                    'profit_estimate' => round((float) $todayRevenue * 0.28, 2),
                ],
                'period' => [
                    'revenue' => (float) $periodRevenue,
                    'transactions' => $periodTransactions,
                ],
                'inventory' => [
                    'total_products' => $totalProducts,
                    'total_units' => (int) $totalUnits,
                    'inventory_value' => round($inventoryValue, 2),
                    'low_stock_count' => $lowStockCount,
                ],
                'payment_breakdown' => $paymentBreakdown,
                'sales_trend' => $salesOverTime,
                'top_products' => $topProducts,
            ],
        ]);
    }
}
