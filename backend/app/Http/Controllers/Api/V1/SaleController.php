<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductBarcode;
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
            ->with(['items.productBarcodes', 'customer'])
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
            'customer_id' => ['nullable', 'integer'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.barcodes' => ['sometimes', 'array'],
            'items.*.barcodes.*' => ['required', 'string', 'max:100', 'distinct'],
        ]);
        $settings = $business->posSettings();

        if (! in_array($validated['payment_method'], $settings['payment_methods'], true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'This payment method is disabled for the business.',
            ]);
        }
        if (($validated['discount_percent'] ?? 0) > 0
            && (! $settings['discount_enabled'] || $validated['discount_percent'] > (float) $settings['max_discount_percent'])) {
            throw ValidationException::withMessages([
                'discount_percent' => 'This discount exceeds the discount limit configured for the business.',
            ]);
        }

        $sale = DB::transaction(function () use ($business, $request, $validated, $settings): Sale {
            $items = collect($validated['items'])->sortBy('product_id')->values();
            $productIds = $items->pluck('product_id');
            $customer = null;
            if (! empty($validated['customer_id'])) {
                $customer = Customer::query()
                    ->where('business_id', $business->id)
                    ->where('status', 'active')
                    ->find($validated['customer_id']);
                if (! $customer) {
                    abort(404, 'Customer not found.');
                }
            }
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

            $register = CashRegister::query()
                ->where('business_id', $business->id)
                ->where('code', 'REG-01')
                ->where('is_active', true)
                ->first();
            $session = $register ? CashRegisterSession::query()
                ->where('business_id', $business->id)
                ->where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first() : null;

            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Open the cash register before recording a sale.',
                ]);
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

                $itemBarcodes = $item['barcodes'] ?? [];
                if ($product->barcode_tracking_enabled && count($itemBarcodes) !== $item['quantity']) {
                    throw ValidationException::withMessages([
                        "items.{$index}.barcodes" => "Scan each individual {$product->name} barcode before checkout.",
                    ]);
                }
                if (! $product->barcode_tracking_enabled && $itemBarcodes !== []) {
                    throw ValidationException::withMessages([
                        "items.{$index}.barcodes" => 'This product does not use individual barcode tracking.',
                    ]);
                }
                if ($product->barcode_tracking_enabled && $itemBarcodes === []) {
                    throw ValidationException::withMessages([
                        "items.{$index}.barcodes" => "Scan each individual {$product->name} barcode before checkout.",
                    ]);
                }
                if ($itemBarcodes !== []) {
                    $availableBarcodes = ProductBarcode::query()
                        ->where('business_id', $business->id)
                        ->where('product_id', $product->id)
                        ->where('status', 'in_stock')
                        ->whereIn('barcode', $itemBarcodes)
                        ->lockForUpdate()
                        ->get();
                    if ($availableBarcodes->count() !== count($itemBarcodes)) {
                        throw ValidationException::withMessages([
                            "items.{$index}.barcodes" => 'One or more scanned barcodes are not in stock or have already been sold.',
                        ]);
                    }
                }

                $lineTotalCents = $this->toCents($product->unit_price) * $item['quantity'];
                if ($lineTotalCents > 999999999999 - $subtotalCents) {
                    throw ValidationException::withMessages(['items' => 'The sale total exceeds the supported amount.']);
                }
                $subtotalCents += $lineTotalCents;
            }

            $discountBasisPoints = (int) round((float) ($validated['discount_percent'] ?? 0) * 100);
            $discountCents = (int) round($subtotalCents * $discountBasisPoints / 10000);
            $taxableCents = $subtotalCents - $discountCents;
            $taxRateBasisPoints = $settings['tax_enabled'] ? (int) round((float) $settings['tax_rate'] * 100) : 0;
            $taxCents = $taxRateBasisPoints === 0
                ? 0
                : ($settings['tax_inclusive']
                    ? (int) round($taxableCents * $taxRateBasisPoints / (10000 + $taxRateBasisPoints))
                    : (int) round($taxableCents * $taxRateBasisPoints / 10000));
            $totalCents = $settings['tax_inclusive'] ? $taxableCents : $taxableCents + $taxCents;

            if ($totalCents > 999999999999) {
                throw ValidationException::withMessages(['items' => 'The sale total exceeds the supported amount.']);
            }

            $receiptNumber = 'POS-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
            $sale = Sale::create([
                'business_id' => $business->id,
                'user_id' => $request->user()->id,
                'customer_id' => $customer?->id,
                'receipt_number' => $receiptNumber,
                'payment_method' => $validated['payment_method'],
                'status' => $validated['payment_method'] === 'credit' ? 'on_credit' : 'paid',
                'subtotal' => $this->fromCents($subtotalCents),
                'discount_amount' => $this->fromCents($discountCents),
                'tax_amount' => $this->fromCents($taxCents),
                'tax_rate' => number_format($taxRateBasisPoints / 100, 2, '.', ''),
                'total' => $this->fromCents($totalCents),
            ]);

            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id']);
                $stock = $stocks->get($item['product_id']);
                $quantityBefore = $stock->quantity_on_hand;
                $quantityAfter = $quantityBefore - $item['quantity'];
                $lineTotalCents = $this->toCents($product->unit_price) * $item['quantity'];

                $saleItem = $sale->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->unit_price,
                    'unit_cost' => $product->cost_price,
                    'line_total' => $this->fromCents($lineTotalCents),
                ]);
                if (! empty($item['barcodes'])) {
                    ProductBarcode::query()
                        ->where('business_id', $business->id)
                        ->where('product_id', $product->id)
                        ->where('status', 'in_stock')
                        ->whereIn('barcode', $item['barcodes'])
                        ->update(['status' => 'sold', 'sale_item_id' => $saleItem->id]);
                }
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

            if ($validated['payment_method'] === 'cash') {
                $session->update([
                    'expected_cash' => $this->fromCents(
                        $this->toCents($session->expected_cash) + $totalCents
                    ),
                ]);
            }

            return $sale->load('items.productBarcodes');
        });

        return response()->json(['data' => $this->saleData($sale)], 201);
    }

    public function show(Request $request, int $sale): JsonResponse
    {
        $business = $request->attributes->get('business');
        $record = Sale::query()->where('business_id', $business->id)->with(['items.productBarcodes', 'customer'])->findOrFail($sale);

        return response()->json(['data' => $this->saleData($record)]);
    }

    private function saleData(Sale $sale): array
    {
        return [
            ...$sale->only(['id', 'customer_id', 'receipt_number', 'payment_method', 'status', 'subtotal', 'discount_amount', 'tax_amount', 'tax_rate', 'total', 'created_at']),
            'customer' => $sale->customer?->only(['id', 'name', 'phone', 'email']),
            'items' => $sale->items->map(fn ($item): array => [
                ...$item->only(['product_id', 'sku', 'product_name', 'quantity', 'unit_price', 'unit_cost', 'line_total']),
                'id' => $item->id,
                'barcodes' => $item->productBarcodes->pluck('barcode')->all(),
            ])->all(),
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
