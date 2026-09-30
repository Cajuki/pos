<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $purchases = PurchaseOrder::query()
            ->where('business_id', $business->id)
            ->with(['supplier', 'items'])
            ->latest()
            ->paginate(25);

        return response()->json([
            'data' => $purchases->items(),
            'meta' => [
                'current_page' => $purchases->currentPage(),
                'last_page' => $purchases->lastPage(),
                'total' => $purchases->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('business_id', $business->id)],
            'warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('business_id', $business->id)],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $business->id)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $purchase = DB::transaction(function () use ($business, $request, $validated): PurchaseOrder {
            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                $subtotal += $item['quantity'] * $item['unit_cost'];
            }

            $referenceNumber = 'PO-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));

            $order = PurchaseOrder::create([
                'business_id' => $business->id,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'created_by' => $request->user()->id,
                'reference_number' => $referenceNumber,
                'status' => 'received',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total' => $subtotal,
                'ordered_at' => now(),
                'received_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $product = Product::query()
                    ->where('business_id', $business->id)
                    ->findOrFail($item['product_id']);
                $lineTotal = $item['quantity'] * $item['unit_cost'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity_ordered' => $item['quantity'],
                    'quantity_received' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'tax_amount' => 0,
                    'line_total' => $lineTotal,
                ]);

                // Update inventory and stock movements
                $stock = InventoryStock::firstOrCreate(
                    ['business_id' => $business->id, 'product_id' => $product->id],
                    ['quantity_on_hand' => 0]
                );

                $quantityBefore = $stock->quantity_on_hand;
                $stock->increment('quantity_on_hand', $item['quantity']);

                StockMovement::create([
                    'business_id' => $business->id,
                    'inventory_stock_id' => $stock->id,
                    'product_id' => $product->id,
                    'type' => 'purchase',
                    'quantity_change' => $item['quantity'],
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $stock->quantity_on_hand,
                    'reason' => "Purchase order {$referenceNumber}",
                ]);
            }

            return $order->load(['supplier', 'items']);
        });

        return response()->json(['data' => $purchase], 201);
    }
}
