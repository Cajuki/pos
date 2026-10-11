<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\RestaurantOrder;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RestaurantOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $orders = RestaurantOrder::query()
            ->where('business_id', $business->id)
            ->with(['customer:id,name', 'items.product:id,sku,name,product_type,unit_price', 'sale:id,receipt_number,total'])
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $business->id)],
            'order_type' => ['required', Rule::in(['dine_in', 'takeaway', 'delivery'])],
            'table_name' => ['nullable', 'string', 'max:80', 'required_if:order_type,dine_in'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('business_id', $business->id)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $order = DB::transaction(function () use ($business, $request, $validated): RestaurantOrder {
            $items = collect($validated['items'])->sortBy('product_id')->values();
            $products = Product::query()
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->whereIn('id', $items->pluck('product_id'))
                ->get()
                ->keyBy('id');

            if ($products->count() !== $items->count()) {
                abort(404, 'One or more products were not found.');
            }

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                if ($product->batch_tracking_enabled) {
                    throw ValidationException::withMessages([
                        'items' => "Batch-tracked product {$product->name} must be checked out directly so expiry lots are allocated correctly.",
                    ]);
                }
                if ($product->product_type === 'goods' && $product->barcode_tracking_enabled) {
                    throw ValidationException::withMessages([
                        'items' => "Add serialized item {$product->name} through immediate barcode checkout.",
                    ]);
                }
            }

            $productIds = $items->pluck('product_id')
                ->filter(fn (int $id): bool => $products->get($id)->product_type === 'goods');
            $stocks = InventoryStock::query()
                ->where('business_id', $business->id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                if ($product->product_type !== 'goods') {
                    continue;
                }
                $stock = $stocks->get($product->id);
                if (! $stock || $stock->quantity_on_hand - $stock->quantity_reserved < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => "Not enough available stock for {$product->name}.",
                    ]);
                }
            }

            $order = RestaurantOrder::create([
                'business_id' => $business->id,
                'customer_id' => $validated['customer_id'] ?? null,
                'created_by' => $request->user()->id,
                'order_type' => $validated['order_type'],
                'table_name' => $validated['table_name'] ?? null,
                'status' => 'open',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->unit_price,
                    'notes' => $item['notes'] ?? null,
                ]);
                if ($product->product_type === 'goods') {
                    $stocks->get($product->id)->increment('quantity_reserved', $item['quantity']);
                }
            }

            return $order->load(['customer:id,name', 'items.product:id,sku,name,product_type,unit_price']);
        });

        return response()->json(['data' => $order], 201);
    }

    public function updateStatus(Request $request, int $order): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'status' => ['required', Rule::in(['preparing', 'ready', 'served', 'cancelled'])],
        ]);

        $record = DB::transaction(function () use ($business, $order, $validated, $request): RestaurantOrder {
            $record = RestaurantOrder::query()
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->findOrFail($order);

            if ($record->status === 'cancelled' || $record->status === 'paid') {
                throw ValidationException::withMessages(['status' => 'This order is already closed.']);
            }

            $states = ['open' => 0, 'preparing' => 1, 'ready' => 2, 'served' => 3];
            if ($validated['status'] !== 'cancelled'
                && $states[$validated['status']] !== $states[$record->status]) {
                throw ValidationException::withMessages(['status' => 'Kitchen status must move forward one step at a time.']);
            }

            if ($validated['status'] === 'cancelled') {
                $items = $record->items()->with('product')->orderBy('product_id')->get();
                foreach ($items as $item) {
                    if ($item->product->product_type !== 'goods') {
                        continue;
                    }
                    $stock = InventoryStock::query()
                        ->where('business_id', $business->id)
                        ->where('product_id', $item->product_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $stock->decrement('quantity_reserved', $item->quantity);
                    StockMovement::create([
                        'business_id' => $business->id,
                        'inventory_stock_id' => $stock->id,
                        'product_id' => $item->product_id,
                        'created_by' => $request->user()->id,
                        'type' => 'adjustment',
                        'quantity_change' => 0,
                        'quantity_before' => $stock->quantity_on_hand,
                        'quantity_after' => $stock->quantity_on_hand,
                        'reason' => "Cancelled restaurant order {$record->id}; released {$item->quantity} reserved units",
                    ]);
                }
            }

            $record->update(['status' => $validated['status']]);

            return $record->load(['customer:id,name', 'items.product:id,sku,name,product_type,unit_price', 'sale:id,receipt_number,total']);
        });

        return response()->json(['data' => $record]);
    }
}
