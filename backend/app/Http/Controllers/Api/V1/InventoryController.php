<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $stocks = InventoryStock::query()
            ->where('business_id', $business->id)
            ->with('product')
            ->orderBy('product_id')
            ->paginate(50);

        return response()->json([
            'data' => $stocks->getCollection()->map(fn (InventoryStock $stock): array => [
                'product_id' => $stock->product_id,
                'sku' => $stock->product->sku,
                'name' => $stock->product->name,
                'unit_price' => $stock->product->unit_price,
                'quantity_on_hand' => $stock->quantity_on_hand,
                'reorder_level' => $stock->product->reorder_level,
                'is_low_stock' => $stock->quantity_on_hand <= $stock->product->reorder_level,
            ]),
            'meta' => ['current_page' => $stocks->currentPage(), 'last_page' => $stocks->lastPage(), 'total' => $stocks->total()],
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $movements = StockMovement::query()
            ->where('business_id', $business->id)
            ->with('product:id,sku,name')
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $movements->items(),
            'meta' => ['current_page' => $movements->currentPage(), 'last_page' => $movements->lastPage(), 'total' => $movements->total()],
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity_change' => ['required', 'integer', 'not_in:0', 'min:-2147483648', 'max:2147483647'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $movement = DB::transaction(function () use ($business, $request, $validated): StockMovement {
            $stock = InventoryStock::query()
                ->where('business_id', $business->id)
                ->where('product_id', $validated['product_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $quantityBefore = $stock->quantity_on_hand;
            $quantityAfter = $quantityBefore + $validated['quantity_change'];

            if ($quantityAfter < 0 || $quantityAfter > 4294967295) {
                throw ValidationException::withMessages(['quantity_change' => 'The adjustment would put stock outside the supported range.']);
            }

            $stock->update(['quantity_on_hand' => $quantityAfter]);

            return StockMovement::create([
                'business_id' => $business->id,
                'inventory_stock_id' => $stock->id,
                'product_id' => $stock->product_id,
                'created_by' => $request->user()->id,
                'type' => 'adjustment',
                'quantity_change' => $validated['quantity_change'],
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => $validated['reason'],
            ]);
        });

        return response()->json(['data' => $movement->load('product:id,sku,name')], 201);
    }
}
