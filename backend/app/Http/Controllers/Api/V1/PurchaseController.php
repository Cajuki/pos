<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $purchases = PurchaseOrder::query()
            ->where('business_id', $business->id)
            ->with(['supplier', 'items.productBarcodes'])
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
        $items = $request->input('items');
        if (is_array($items)) {
            $items = collect($items)->map(function (mixed $item): mixed {
                if (is_array($item) && isset($item['barcodes']) && is_array($item['barcodes'])) {
                    $item['barcodes'] = array_map(
                        fn (mixed $barcode): mixed => is_string($barcode) ? trim($barcode) : $barcode,
                        $item['barcodes'],
                    );
                }

                return $item;
            })->all();
            $request->merge(['items' => $items]);
        }
        $validated = $request->validate([
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('business_id', $business->id)],
            'warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('business_id', $business->id)],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $business->id)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.barcodes' => ['sometimes', 'array'],
            'items.*.barcodes.*' => [
                'required',
                'string',
                'max:100',
                'distinct',
                Rule::unique('product_barcodes', 'barcode')->where('business_id', $business->id),
                Rule::unique('products', 'barcode')->where('business_id', $business->id),
            ],
        ]);
        $allBarcodes = collect($validated['items'])->flatMap(fn (array $item): array => $item['barcodes'] ?? [])->all();
        if (count($allBarcodes) !== count(array_unique($allBarcodes))) {
            throw ValidationException::withMessages([
                'items' => 'Each individually tracked unit must have a different barcode.',
            ]);
        }

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
                if ($product->product_type !== 'goods') {
                    throw ValidationException::withMessages([
                        'items' => "Services such as {$product->name} cannot be received as inventory.",
                    ]);
                }
                if ($product->batch_tracking_enabled) {
                    throw ValidationException::withMessages([
                        'items' => "{$product->name} requires batch number and expiry details; receive it through Inventory batch receiving.",
                    ]);
                }
                $barcodes = $item['barcodes'] ?? [];
                if ($product->barcode_tracking_enabled && count($barcodes) !== $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => "Scan one unique barcode for each {$product->name} unit being received.",
                    ]);
                }
                if (! $product->barcode_tracking_enabled && $barcodes !== []) {
                    throw ValidationException::withMessages([
                        'items' => "{$product->name} uses quantity-based stock; do not scan individual unit barcodes.",
                    ]);
                }
                $lineTotal = $item['quantity'] * $item['unit_cost'];

                $purchaseItem = $order->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity_ordered' => $item['quantity'],
                    'quantity_received' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'tax_amount' => 0,
                    'line_total' => $lineTotal,
                ]);

                InventoryStock::firstOrCreate(
                    ['business_id' => $business->id, 'product_id' => $product->id],
                    ['quantity_on_hand' => 0]
                );
                $stock = InventoryStock::query()
                    ->where('business_id', $business->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $quantityBefore = $stock->quantity_on_hand;
                if ($item['quantity'] > 2147483647 - $quantityBefore) {
                    throw ValidationException::withMessages([
                        'items' => "Receiving this quantity would exceed the supported stock limit for {$product->name}.",
                    ]);
                }
                $stock->increment('quantity_on_hand', $item['quantity']);

                foreach ($barcodes as $barcode) {
                    ProductBarcode::create([
                        'business_id' => $business->id,
                        'product_id' => $product->id,
                        'purchase_item_id' => $purchaseItem->id,
                        'barcode' => $barcode,
                        'status' => 'in_stock',
                    ]);
                }

                StockMovement::create([
                    'business_id' => $business->id,
                    'inventory_stock_id' => $stock->id,
                    'product_id' => $product->id,
                    'created_by' => $request->user()->id,
                    'type' => 'purchase',
                    'quantity_change' => $item['quantity'],
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $stock->quantity_on_hand,
                    'reason' => "Purchase order {$referenceNumber}",
                ]);
            }

            return $order->load(['supplier', 'items.productBarcodes']);
        });

        return response()->json(['data' => $purchase], 201);
    }
}
