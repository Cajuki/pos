<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $sales = Sale::query()
            ->where('business_id', $business->id)
            ->with('items')
            ->latest()
            ->paginate(25);

        return response()->json([
            'data' => $sales->getCollection()->map(fn (Sale $sale): array => $this->saleData($sale)),
            'meta' => ['current_page' => $sales->currentPage(), 'last_page' => $sales->lastPage(), 'total' => $sales->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'payment_method' => ['required', 'in:cash,credit,mpesa,card,bank'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $sale = DB::transaction(function () use ($business, $request, $validated): Sale {
            $items = collect($validated['items'])->sortBy('product_id')->values();
            $productIds = $items->pluck('product_id');
            $products = Product::query()
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            if ($products->count() !== $items->count()) {
                abort(404, 'One or more products were not found.');
            }

            $stocks = InventoryStock::query()
                ->where('business_id', $business->id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            if ($stocks->count() !== $items->count()) {
                abort(404, 'Inventory record not found.');
            }

            $subtotalCents = 0;
            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id']);
                $stock = $stocks->get($item['product_id']);

                if ($stock->quantity_on_hand < $item['quantity']) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Insufficient stock for {$product->name}.",
                    ]);
                }

                $lineTotalCents = $this->toCents($product->unit_price) * $item['quantity'];
                if ($lineTotalCents > 999999999999 - $subtotalCents) {
                    throw ValidationException::withMessages(['items' => 'The sale total exceeds the supported amount.']);
                }
                $subtotalCents += $lineTotalCents;
            }

            $receiptNumber = 'POS-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
            $sale = Sale::create([
                'business_id' => $business->id,
                'user_id' => $request->user()->id,
                'receipt_number' => $receiptNumber,
                'payment_method' => $validated['payment_method'],
                'status' => $validated['payment_method'] === 'credit' ? 'on_credit' : 'paid',
                'subtotal' => $this->fromCents($subtotalCents),
                'total' => $this->fromCents($subtotalCents),
            ]);

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $stock = $stocks->get($item['product_id']);
                $quantityBefore = $stock->quantity_on_hand;
                $quantityAfter = $quantityBefore - $item['quantity'];
                $lineTotalCents = $this->toCents($product->unit_price) * $item['quantity'];

                $sale->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->unit_price,
                    'unit_cost' => $product->cost_price,
                    'line_total' => $this->fromCents($lineTotalCents),
                ]);
                $stock->update(['quantity_on_hand' => $quantityAfter]);
                StockMovement::create([
                    'business_id' => $business->id,
                    'inventory_stock_id' => $stock->id,
                    'product_id' => $product->id,
                    'sale_id' => $sale->id,
                    'created_by' => $request->user()->id,
                    'type' => 'sale',
                    'quantity_change' => -$item['quantity'],
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'reason' => 'Sale '.$receiptNumber,
                ]);
            }

            return $sale->load('items');
        });

        return response()->json(['data' => $this->saleData($sale)], 201);
    }

    public function show(Request $request, int $sale): JsonResponse
    {
        $business = $request->attributes->get('business');
        $record = Sale::query()->where('business_id', $business->id)->with('items')->findOrFail($sale);

        return response()->json(['data' => $this->saleData($record)]);
    }

    private function saleData(Sale $sale): array
    {
        return [
            ...$sale->only(['id', 'receipt_number', 'payment_method', 'status', 'subtotal', 'total', 'created_at']),
            'items' => $sale->items->map(fn ($item): array => $item->only(['product_id', 'sku', 'product_name', 'quantity', 'unit_price', 'unit_cost', 'line_total']))->all(),
        ];
    }

    private function toCents(string $amount): int
    {
        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $units * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
