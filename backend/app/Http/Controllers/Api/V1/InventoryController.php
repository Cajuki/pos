<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $stocks = InventoryStock::query()
            ->where('business_id', $business->id)
            ->whereHas('product', fn ($query) => $query->where('product_type', 'goods'))
            ->with('product')
            ->orderBy('product_id')
            ->paginate(50);

        return response()->json([
            'data' => $stocks->getCollection()->map(fn (InventoryStock $stock): array => [
                'product_id' => $stock->product_id,
                'sku' => $stock->product->sku,
                'barcode' => $stock->product->barcode,
                'name' => $stock->product->name,
                'unit_price' => $stock->product->unit_price,
                'quantity_on_hand' => $stock->quantity_on_hand,
                'quantity_reserved' => $stock->quantity_reserved,
                'quantity_available' => $stock->quantity_on_hand - $stock->quantity_reserved,
                'reorder_level' => $stock->product->reorder_level,
                'barcode_tracking_enabled' => $stock->product->barcode_tracking_enabled,
                'batch_tracking_enabled' => $stock->product->batch_tracking_enabled,
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

            if ($stock->product()->value('barcode_tracking_enabled')) {
                throw ValidationException::withMessages([
                    'product_id' => 'Use barcode intake or checkout to change individually tracked stock.',
                ]);
            }
            if ($stock->product()->value('batch_tracking_enabled')) {
                throw ValidationException::withMessages([
                    'product_id' => 'Use batch receiving or batch-aware checkout for this product.',
                ]);
            }
            if ($stock->product()->value('product_type') !== 'goods') {
                throw ValidationException::withMessages([
                    'product_id' => 'Services do not use stock adjustments.',
                ]);
            }

            $quantityBefore = $stock->quantity_on_hand;
            $quantityAfter = $quantityBefore + $validated['quantity_change'];

            if ($quantityAfter < $stock->quantity_reserved) {
                throw ValidationException::withMessages(['quantity_change' => 'The adjustment cannot reduce stock below units reserved for open restaurant orders.']);
            }
            if ($quantityAfter > 2147483647) {
                throw ValidationException::withMessages(['quantity_change' => 'The adjustment would exceed the supported stock range.']);
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

    public function receiveBarcode(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'barcode' => [
                'required',
                'string',
                'max:100',
                Rule::unique('product_barcodes', 'barcode')->where('business_id', $business->id),
                Rule::unique('products', 'barcode')->where('business_id', $business->id),
            ],
        ]);

        $result = DB::transaction(function () use ($business, $request, $validated): array {
            $product = Product::query()
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($validated['product_id']);
            $stock = InventoryStock::query()
                ->where('business_id', $business->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $product->barcode_tracking_enabled) {
                throw ValidationException::withMessages([
                    'product_id' => 'This product uses a shared barcode. Select unique per-unit barcode tracking when creating serialized products.',
                ]);
            }

            $barcode = ProductBarcode::create([
                'business_id' => $business->id,
                'product_id' => $product->id,
                'barcode' => $validated['barcode'],
                'status' => 'in_stock',
            ]);
            $quantityBefore = $stock->quantity_on_hand;
            if ($quantityBefore >= 2147483647) {
                throw ValidationException::withMessages([
                    'barcode' => 'The product has reached the supported stock limit.',
                ]);
            }
            $stock->increment('quantity_on_hand');

            StockMovement::create([
                'business_id' => $business->id,
                'inventory_stock_id' => $stock->id,
                'product_id' => $product->id,
                'created_by' => $request->user()->id,
                'type' => 'adjustment',
                'quantity_change' => 1,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityBefore + 1,
                'reason' => 'Received individually barcoded stock',
            ]);

            return [
                'barcode' => $barcode->barcode,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity_on_hand' => $quantityBefore + 1,
            ];
        });

        return response()->json(['data' => $result], 201);
    }

    public function batches(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $today = today();
        $expirySummary = InventoryBatch::query()
            ->where('business_id', $business->id)
            ->where('quantity_remaining', '>', 0)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN expires_on < ? THEN quantity_remaining ELSE 0 END), 0) AS expired_units,
                COALESCE(SUM(CASE WHEN expires_on >= ? AND expires_on <= ? THEN quantity_remaining ELSE 0 END), 0) AS expiring_units',
                [$today->toDateString(), $today->toDateString(), $today->copy()->addDays(30)->toDateString()],
            )
            ->first();
        $batches = InventoryBatch::query()
            ->where('business_id', $business->id)
            ->with('product:id,sku,name')
            ->orderBy('expires_on')
            ->paginate(50);

        return response()->json([
            'data' => $batches->items(),
            'meta' => [
                'current_page' => $batches->currentPage(),
                'last_page' => $batches->lastPage(),
                'total' => $batches->total(),
                'expiry_alerts' => [
                    'as_of' => $today->toDateString(),
                    'expired_units' => (int) $expirySummary->expired_units,
                    'expiring_units' => (int) $expirySummary->expiring_units,
                ],
            ],
        ]);
    }

    public function receiveBatch(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless(in_array('pharmacy', $business->posSettings()['enabled_modules'], true), 404, 'The pharmacy inventory module is not enabled.');
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'lot_number' => ['required', 'string', 'max:100'],
            'expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'unit_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
        ]);

        $result = DB::transaction(function () use ($business, $request, $validated): array {
            $product = Product::query()
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($validated['product_id']);
            if (! $product->batch_tracking_enabled || $product->product_type !== 'goods') {
                throw ValidationException::withMessages([
                    'product_id' => 'Select an active goods product with pharmacy batch tracking enabled.',
                ]);
            }

            $stock = InventoryStock::query()
                ->where('business_id', $business->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();
            $batch = InventoryBatch::query()
                ->where('business_id', $business->id)
                ->where('product_id', $product->id)
                ->where('lot_number', $validated['lot_number'])
                ->lockForUpdate()
                ->first();
            $unitCost = isset($validated['unit_cost'])
                ? number_format((float) $validated['unit_cost'], 2, '.', '')
                : null;
            if ($batch && ($batch->expires_on->toDateString() !== $validated['expires_on']
                || $batch->unit_cost !== $unitCost)) {
                throw ValidationException::withMessages([
                    'lot_number' => 'An existing lot must retain the same expiry date and unit cost.',
                ]);
            }

            $quantityBefore = $stock->quantity_on_hand;
            $quantityAfter = $quantityBefore + $validated['quantity'];
            if ($quantityAfter > 2147483647) {
                throw ValidationException::withMessages(['quantity' => 'The product has reached the supported stock limit.']);
            }

            if ($batch) {
                $batch->increment('quantity_received', $validated['quantity']);
                $batch->increment('quantity_remaining', $validated['quantity']);
                $batch->refresh();
            } else {
                $batch = InventoryBatch::create([
                    'business_id' => $business->id,
                    'product_id' => $product->id,
                    'lot_number' => $validated['lot_number'],
                    'expires_on' => $validated['expires_on'],
                    'quantity_received' => $validated['quantity'],
                    'quantity_remaining' => $validated['quantity'],
                    'unit_cost' => $unitCost,
                    'received_by' => $request->user()->id,
                ]);
            }
            $stock->update(['quantity_on_hand' => $quantityAfter]);
            StockMovement::create([
                'business_id' => $business->id,
                'inventory_stock_id' => $stock->id,
                'product_id' => $product->id,
                'created_by' => $request->user()->id,
                'type' => 'receipt',
                'quantity_change' => $validated['quantity'],
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => 'Received lot '.$batch->lot_number,
            ]);

            return $batch->load('product:id,sku,name')->toArray();
        });

        return response()->json(['data' => $result], 201);
    }
}
