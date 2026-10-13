<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\CommissionEntry;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\RestaurantOrder;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\StaffProfile;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $sales = Sale::query()
            ->where('business_id', $business->id)
            ->with([
                'items' => fn ($query) => $query->withSum([
                    'refundItems as refunded_quantity' => fn ($refundItems) => $refundItems->whereHas(
                        'refund',
                        fn ($refund) => $refund->where('status', 'completed'),
                    ),
                ], 'quantity')->with(['productBarcodes', 'batches.batch:id,lot_number,expires_on']),
                'customer',
            ])
            ->withSum([
                'refunds as refunded_total' => fn ($refunds) => $refunds->where('status', 'completed'),
            ], 'amount')
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
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey !== null && (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 120)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'The idempotency key must be between 1 and 120 characters.']);
        }
        $idempotencyHash = $idempotencyKey === null ? null : hash('sha256', json_encode(
            $this->sortRequestData($request->all()),
            JSON_THROW_ON_ERROR,
        ));
        if ($idempotencyKey !== null) {
            $existingSale = $this->saleForIdempotencyKey($business->id, $idempotencyKey);
            if ($existingSale) {
                return $this->idempotencyResponse($existingSale, $idempotencyHash);
            }
        }
        $restaurantOrder = null;
        if ($request->filled('restaurant_order_id')) {
            $restaurantOrder = RestaurantOrder::query()
                ->where('business_id', $business->id)
                ->with('items')
                ->findOrFail($request->input('restaurant_order_id'));

            if (in_array($restaurantOrder->status, ['paid', 'cancelled'], true) || $restaurantOrder->sale_id) {
                throw ValidationException::withMessages(['restaurant_order_id' => 'This table order has already been closed.']);
            }

            $request->merge([
                'customer_id' => $restaurantOrder->customer_id,
                'order_type' => $restaurantOrder->order_type,
                'table_name' => $restaurantOrder->table_name,
                'items' => $restaurantOrder->items->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ])->all(),
            ]);
        } elseif ($request->filled('appointment_id')) {
            $appointmentContext = Appointment::query()
                ->where('business_id', $business->id)
                ->findOrFail($request->input('appointment_id'));
            $request->merge([
                'customer_id' => $appointmentContext->customer_id,
                'items' => [[
                    'product_id' => $appointmentContext->service_product_id,
                    'quantity' => 1,
                ]],
            ]);
        }
        $validated = $request->validate([
            'payment_method' => ['required', 'in:cash,credit,mpesa,card,bank'],
            'cash_received' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'prohibited_unless:payment_method,cash'],
            'customer_id' => ['nullable', 'integer'],
            'cash_register_id' => ['sometimes', 'integer', Rule::exists('cash_registers', 'id')->where('business_id', $business->id)],
            'restaurant_order_id' => ['sometimes', 'integer', Rule::exists('restaurant_orders', 'id')->where('business_id', $business->id)],
            'appointment_id' => ['sometimes', 'integer', Rule::exists('appointments', 'id')->where('business_id', $business->id)],
            'order_type' => ['sometimes', Rule::in(['dine_in', 'takeaway', 'delivery'])],
            'table_name' => ['nullable', 'string', 'max:80', 'required_if:order_type,dine_in'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.barcodes' => ['sometimes', 'array'],
            'items.*.barcodes.*' => ['required', 'string', 'max:100', 'distinct'],
            'items.*.performed_by_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('business_user', 'user_id')->where('business_id', $business->id)],
        ]);
        if (isset($validated['restaurant_order_id'], $validated['appointment_id'])) {
            throw ValidationException::withMessages([
                'appointment_id' => 'An appointment checkout cannot be combined with a restaurant order.',
            ]);
        }
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

        $sale = DB::transaction(function () use ($business, $request, $validated, $settings, $restaurantOrder, $idempotencyKey, $idempotencyHash): Sale {
            $lockedOrder = $restaurantOrder ? RestaurantOrder::query()
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->findOrFail($restaurantOrder->id) : null;
            if ($lockedOrder && (in_array($lockedOrder->status, ['paid', 'cancelled'], true) || $lockedOrder->sale_id)) {
                throw ValidationException::withMessages(['restaurant_order_id' => 'This table order has already been closed.']);
            }
            $appointment = isset($validated['appointment_id'])
                ? Appointment::query()
                    ->where('business_id', $business->id)
                    ->lockForUpdate()
                    ->findOrFail($validated['appointment_id'])
                : null;
            if ($appointment && (! in_array($appointment->status, ['booked', 'in_progress'], true) || $appointment->sale_id)) {
                throw ValidationException::withMessages(['appointment_id' => 'This appointment has already been closed.']);
            }
            $lockedOrderItems = $lockedOrder?->items()->lockForUpdate()->get()->keyBy('product_id');
            $items = collect($validated['items'])->sortBy('product_id')->values();
            if ($appointment && ($items->count() !== 1
                || $items->first()['product_id'] !== $appointment->service_product_id
                || $items->first()['quantity'] !== 1)) {
                throw ValidationException::withMessages([
                    'items' => 'Appointment checkout must contain exactly its booked service.',
                ]);
            }
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
            $batchProducts = $products->filter(fn (Product $product): bool => $product->batch_tracking_enabled);
            $batchesByProduct = InventoryBatch::query()
                ->where('business_id', $business->id)
                ->whereIn('product_id', $batchProducts->keys())
                ->whereDate('expires_on', '>=', today())
                ->where('quantity_remaining', '>', 0)
                ->orderBy('expires_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('product_id');

            $registerQuery = CashRegister::query()
                ->where('business_id', $business->id)
                ->where('is_active', true);
            if (isset($validated['cash_register_id'])) {
                $registerQuery->whereKey($validated['cash_register_id']);
            } else {
                $registerQuery->where('code', 'REG-01');
            }
            $register = $registerQuery->first();
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
            if ($appointment?->branch_id && $register->branch_id !== $appointment->branch_id) {
                throw ValidationException::withMessages(['appointment_id' => 'Charge this appointment at a till in the same branch.']);
            }
            if ($lockedOrder?->restaurant_table_id) {
                $table = RestaurantTable::query()
                    ->where('business_id', $business->id)
                    ->whereKey($lockedOrder->restaurant_table_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($register->branch_id !== $table->branch_id) {
                    throw ValidationException::withMessages(['restaurant_order_id' => 'Settle this table order at a till in the same branch.']);
                }
            }

            $subtotalCents = 0;
            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id']);
                $stock = $stocks->get($item['product_id']);

                if ($product->prescription_required) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_id" => "{$product->name} requires professional prescription verification before dispensing.",
                    ]);
                }

                if ($product->product_type === 'goods'
                    && ($lockedOrder
                        ? $stock->quantity_reserved < $item['quantity'] || $stock->quantity_on_hand < $item['quantity']
                        : $stock->quantity_on_hand - $stock->quantity_reserved < $item['quantity'])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Insufficient stock for {$product->name}.",
                    ]);
                }
                if ($product->batch_tracking_enabled
                    && ($batchesByProduct->get($product->id, collect())->sum('quantity_remaining') < $item['quantity'])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Not enough unexpired batch stock for {$product->name}.",
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

                $unitPrice = $lockedOrderItems?->get($product->id)?->unit_price ?? $this->bulkUnitPrice($product, $item['quantity']);
                $lineTotalCents = $this->toCents($unitPrice) * $item['quantity'];
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

            $cashReceivedCents = null;
            $changeGivenCents = null;
            if ($validated['payment_method'] === 'cash') {
                $cashReceivedCents = isset($validated['cash_received'])
                    ? $this->toCents((string) $validated['cash_received'])
                    : $totalCents;

                if ($cashReceivedCents < $totalCents) {
                    throw ValidationException::withMessages([
                        'cash_received' => 'Cash received must be at least the sale total.',
                    ]);
                }

                $changeGivenCents = $cashReceivedCents - $totalCents;
            }

            $receiptNumber = 'POS-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
            $sale = Sale::create([
                'business_id' => $business->id,
                'user_id' => $request->user()->id,
                'cash_register_id' => $register->id,
                'customer_id' => $customer?->id,
                'receipt_number' => $receiptNumber,
                'payment_method' => $validated['payment_method'],
                'status' => $validated['payment_method'] === 'credit' ? 'on_credit' : 'paid',
                'order_type' => $validated['order_type'] ?? null,
                'table_name' => $validated['table_name'] ?? null,
                'subtotal' => $this->fromCents($subtotalCents),
                'discount_amount' => $this->fromCents($discountCents),
                'tax_amount' => $this->fromCents($taxCents),
                'tax_rate' => number_format($taxRateBasisPoints / 100, 2, '.', ''),
                'total' => $this->fromCents($totalCents),
                'cash_received' => $cashReceivedCents === null ? null : $this->fromCents($cashReceivedCents),
                'change_given' => $changeGivenCents === null ? null : $this->fromCents($changeGivenCents),
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $idempotencyHash,
            ]);

            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id']);
                $stock = $stocks->get($item['product_id']);
                $quantityBefore = $stock->quantity_on_hand;
                $quantityAfter = $quantityBefore - $item['quantity'];
                $unitPrice = $lockedOrderItems?->get($product->id)?->unit_price ?? $this->bulkUnitPrice($product, $item['quantity']);
                $lineTotalCents = $this->toCents($unitPrice) * $item['quantity'];

                $saleItem = $sale->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'unit_cost' => $product->cost_price,
                    'line_total' => $this->fromCents($lineTotalCents),
                    'performed_by_user_id' => $appointment?->staff_user_id ?? ($item['performed_by_user_id'] ?? null),
                ]);
                $commissionStaffId = $appointment?->staff_user_id ?? ($item['performed_by_user_id'] ?? null);
                $staffProfile = $appointment?->staffProfile;
                if (! $staffProfile && $commissionStaffId) {
                    $staffProfile = StaffProfile::query()->where('business_id', $business->id)->where('user_id', $commissionStaffId)->first();
                }
                $commissionType = $staffProfile?->commission_type ?? $product->commission_type;
                $commissionValue = $staffProfile?->commission_value ?? $product->commission_value;
                if ($product->product_type === 'goods' && $staffProfile?->product_commission_enabled) {
                    $commissionType = 'percentage';
                    $commissionValue = $staffProfile->product_commission_value;
                }
                if ($sale->status === 'paid' && ($commissionStaffId || $staffProfile) && $commissionType
                    && $this->toCents((string) $commissionValue) > 0) {
                    $discountBasisPoints = $this->toCents((string) ($validated['discount_percent'] ?? '0'));
                    $commissionBasisCents = intdiv(($lineTotalCents * (10000 - $discountBasisPoints)) + 5000, 10000);
                    $commissionCents = $commissionType === 'percentage'
                        ? intdiv(($commissionBasisCents * $this->toCents((string) $commissionValue)) + 5000, 10000)
                        : $this->toCents((string) $commissionValue) * $item['quantity'];
                    if ($commissionCents > 0) {
                        CommissionEntry::create([
                            'business_id' => $business->id,
                            'branch_id' => $register->branch_id,
                            'sale_id' => $sale->id,
                            'sale_item_id' => $saleItem->id,
                            'staff_user_id' => $commissionStaffId,
                            'staff_profile_id' => $staffProfile?->id,
                            'basis_amount' => $this->fromCents($commissionBasisCents),
                            'earned_amount' => $this->fromCents($commissionCents),
                            'reversed_amount' => '0.00',
                            'status' => 'pending',
                        ]);
                    }
                }
                if (! empty($item['barcodes'])) {
                    ProductBarcode::query()
                        ->where('business_id', $business->id)
                        ->where('product_id', $product->id)
                        ->where('status', 'in_stock')
                        ->whereIn('barcode', $item['barcodes'])
                        ->update(['status' => 'sold', 'sale_item_id' => $saleItem->id]);
                }
                if ($product->product_type === 'goods') {
                    if ($product->batch_tracking_enabled) {
                        $quantityRemaining = $item['quantity'];
                        foreach ($batchesByProduct->get($product->id, collect()) as $batch) {
                            if ($quantityRemaining === 0) {
                                break;
                            }
                            $taken = min($quantityRemaining, $batch->quantity_remaining);
                            if ($taken === 0) {
                                continue;
                            }
                            $batch->decrement('quantity_remaining', $taken);
                            $saleItem->batches()->create([
                                'inventory_batch_id' => $batch->id,
                                'quantity' => $taken,
                            ]);
                            $quantityRemaining -= $taken;
                        }
                        if ($quantityRemaining > 0) {
                            throw ValidationException::withMessages([
                                "items.{$index}.quantity" => "Not enough unexpired batch stock for {$product->name}.",
                            ]);
                        }
                    }
                    $stock->update([
                        'quantity_on_hand' => $quantityAfter,
                        'quantity_reserved' => max(0, $stock->quantity_reserved - ($lockedOrder ? $item['quantity'] : 0)),
                    ]);
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
            }

            if ($lockedOrder) {
                $lockedOrder->update(['status' => 'paid', 'sale_id' => $sale->id]);
                if ($lockedOrder->restaurant_table_id) {
                    RestaurantTable::query()
                        ->where('business_id', $business->id)
                        ->whereKey($lockedOrder->restaurant_table_id)
                        ->lockForUpdate()
                        ->update(['status' => 'cleaning']);
                }
            }
            if ($appointment) {
                $appointment->update(['status' => 'completed', 'sale_id' => $sale->id]);
            }
            if ($validated['payment_method'] === 'cash') {
                $session->update([
                    'expected_cash' => $this->fromCents(
                        $this->toCents($session->expected_cash) + $totalCents
                    ),
                ]);
            }

            return $sale->load(['items.productBarcodes', 'items.batches.batch']);
        });

        return response()->json(['data' => $this->saleData($sale)], 201);
    }

    public function show(Request $request, int $sale): JsonResponse
    {
        $business = $request->attributes->get('business');
        $record = Sale::query()->where('business_id', $business->id)->with([
            'items' => fn ($query) => $query->withSum([
                'refundItems as refunded_quantity' => fn ($refundItems) => $refundItems->whereHas(
                    'refund',
                    fn ($refund) => $refund->where('status', 'completed'),
                ),
            ], 'quantity')->with(['productBarcodes', 'batches.batch:id,lot_number,expires_on']),
            'customer',
        ])->withSum([
            'refunds as refunded_total' => fn ($refunds) => $refunds->where('status', 'completed'),
        ], 'amount')->findOrFail($sale);

        return response()->json(['data' => $this->saleData($record)]);
    }

    private function saleData(Sale $sale): array
    {
        return [
            ...$sale->only(['id', 'cash_register_id', 'customer_id', 'receipt_number', 'payment_method', 'status', 'order_type', 'table_name', 'subtotal', 'discount_amount', 'tax_amount', 'tax_rate', 'total', 'cash_received', 'change_given', 'created_at']),
            'refunded_total' => (string) ($sale->refunded_total ?? '0.00'),
            'customer' => $sale->customer?->only(['id', 'name', 'phone', 'email']),
            'items' => $sale->items->map(fn ($item): array => [
                ...$item->only(['product_id', 'sku', 'barcode', 'product_name', 'quantity', 'unit_price', 'unit_cost', 'line_total', 'performed_by_user_id']),
                'id' => $item->id,
                'refunded_quantity' => (int) ($item->refunded_quantity ?? 0),
                'barcodes' => $item->productBarcodes->pluck('barcode')->all(),
                'batch_allocations' => $item->batches->map(fn ($allocation): array => [
                    'lot_number' => $allocation->batch->lot_number,
                    'expires_on' => $allocation->batch->expires_on->toDateString(),
                    'quantity' => $allocation->quantity,
                ])->all(),
            ])->all(),
        ];
    }

    private function saleForIdempotencyKey(string $businessId, string $idempotencyKey): ?Sale
    {
        return Sale::query()
            ->where('business_id', $businessId)
            ->where('idempotency_key', $idempotencyKey)
            ->with([
                'items' => fn ($query) => $query->withSum([
                    'refundItems as refunded_quantity' => fn ($refundItems) => $refundItems->whereHas(
                        'refund',
                        fn ($refund) => $refund->where('status', 'completed'),
                    ),
                ], 'quantity')->with(['productBarcodes', 'batches.batch']),
                'customer',
            ])
            ->withSum([
                'refunds as refunded_total' => fn ($refunds) => $refunds->where('status', 'completed'),
            ], 'amount')
            ->first();
    }

    private function idempotencyResponse(Sale $sale, string $idempotencyHash): JsonResponse
    {
        if (! hash_equals((string) $sale->idempotency_hash, $idempotencyHash)) {
            return response()->json([
                'message' => 'This idempotency key was already used with a different checkout request.',
            ], 409);
        }

        return response()->json(['data' => $this->saleData($sale)], 200);
    }

    private function sortRequestData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRequestData($value);
            }
        }

        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
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

    private function bulkUnitPrice(Product $product, int $quantity): string
    {
        $unitPrice = (string) $product->unit_price;
        foreach ($product->bulk_pricing ?? [] as $tier) {
            if ($quantity >= $tier['minimum_quantity']) {
                $unitPrice = (string) $tier['unit_price'];
            }
        }

        return $unitPrice;
    }
}
