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

        $todayRefunds = DB::table('refunds')
            ->where('business_id', $business->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', $todayStart)
            ->sum('amount');
        $todayRevenue = $todaySales->sum('total') - $todayRefunds;
        $todayTransactions = $todaySales->count();

        // Total sales in period
        $periodSales = Sale::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $startDate)
            ->get();

        $periodRefunds = DB::table('refunds')
            ->where('business_id', $business->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', $startDate)
            ->sum('amount');
        $periodRevenue = $periodSales->sum('total') - $periodRefunds;
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
        $refundsByPayment = DB::table('refunds')
            ->join('sales', 'sales.id', '=', 'refunds.sale_id')
            ->where('refunds.business_id', $business->id)
            ->where('refunds.status', 'completed')
            ->select('sales.payment_method', DB::raw('sum(refunds.amount) as total'))
            ->groupBy('sales.payment_method')
            ->pluck('total', 'sales.payment_method');
        $paymentBreakdown = Sale::query()
            ->where('business_id', $business->id)
            ->select('payment_method', DB::raw('count(*) as count'), DB::raw('sum(total) as total'))
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method,
                'count' => (int) $row->count,
                'total' => round((float) $row->total - (float) ($refundsByPayment[$row->payment_method] ?? 0), 2),
            ]);

        // Sales over time (daily buckets)
        $salesOverTime = Sale::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('sum(total) as total'), DB::raw('count(*) as count'))
            ->groupBy('day')
            ->orderBy('day')
            ->get();
        $refundsOverTime = DB::table('refunds')
            ->where('business_id', $business->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', $startDate)
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('sum(amount) as total'))
            ->groupBy('day')
            ->get()
            ->keyBy('day');
        $salesTrend = $salesOverTime->mapWithKeys(fn ($row) => [
            (string) $row->day => [
                'date' => (string) $row->day,
                'total' => (float) $row->total,
                'transactions' => (int) $row->count,
            ],
        ]);
        foreach ($refundsOverTime as $day => $refund) {
            $trend = $salesTrend->get((string) $day, [
                'date' => (string) $day,
                'total' => 0.0,
                'transactions' => 0,
            ]);
            $trend['total'] = round($trend['total'] - (float) $refund->total, 2);
            $salesTrend->put((string) $day, $trend);
        }
        $salesTrend = $salesTrend->sortKeys()->values();

        // Top products
        $refundItemTotals = DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->join('sales as refund_sales', 'refund_sales.id', '=', 'refunds.sale_id')
            ->where('refund_sales.business_id', $business->id)
            ->where('refunds.status', 'completed')
            ->groupBy('refund_items.sale_item_id')
            ->select('refund_items.sale_item_id')
            ->selectRaw('SUM(refund_items.quantity) AS returned_quantity')
            ->selectRaw('SUM(refund_items.line_amount) AS returned_amount');
        $topProducts = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.business_id', $business->id)
            ->leftJoinSub($refundItemTotals, 'refund_totals', 'refund_totals.sale_item_id', '=', 'sale_items.id')
            ->select('sale_items.product_name', 'sale_items.sku')
            ->selectRaw('SUM(sale_items.quantity - COALESCE(refund_totals.returned_quantity, 0)) AS units_sold')
            ->selectRaw('SUM(sale_items.line_total - COALESCE(refund_totals.returned_amount, 0)) AS revenue')
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
                'sales_trend' => $salesTrend,
                'top_products' => $topProducts,
            ],
        ]);
    }
}
