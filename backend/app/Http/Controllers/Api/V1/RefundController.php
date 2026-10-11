<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\InventoryBatch;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    public function store(Request $request, int $sale): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sale_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.barcodes' => ['sometimes', 'array'],
            'items.*.barcodes.*' => ['required', 'string', 'max:100', 'distinct'],
        ]);

        $refund = DB::transaction(function () use ($request, $business, $sale, $validated): Refund {
            $record = Sale::query()
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->findOrFail($sale);
            if ($record->status !== 'paid' || $record->payment_method !== 'cash') {
                throw ValidationException::withMessages([
                    'sale' => 'Only completed cash sales can be refunded here. Credit and electronic-payment refunds require their respective settlement workflows.',
                ]);
            }

            $refundItems = collect($validated['items'])->keyBy('sale_item_id');
            $items = $record->items()
                ->whereIn('id', $refundItems->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($items->count() !== $refundItems->count()) {
                throw ValidationException::withMessages(['items' => 'One or more sale items do not belong to this sale.']);
            }

            $totalCents = 0;
            $lines = [];
            foreach ($refundItems as $saleItemId => $requested) {
                /** @var SaleItem $item */
                $item = $items->get($saleItemId);
                $priorQuantity = (int) DB::table('refund_items')
                    ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
                    ->where('refunds.business_id', $business->id)
                    ->where('refunds.sale_id', $record->id)
                    ->where('refunds.status', 'completed')
                    ->where('refund_items.sale_item_id', $item->id)
                    ->sum('refund_items.quantity');
                $quantity = $requested['quantity'];
                if ($priorQuantity + $quantity > $item->quantity) {
                    throw ValidationException::withMessages([
                        "items.{$saleItemId}.quantity" => 'The refund quantity exceeds the unreturned quantity for this item.',
                    ]);
                }

                $product = Product::query()
                    ->where('business_id', $business->id)
                    ->findOrFail($item->product_id);
                $barcodes = $requested['barcodes'] ?? [];
                if ($product->barcode_tracking_enabled) {
                    if (count($barcodes) !== $quantity) {
                        throw ValidationException::withMessages([
                            "items.{$saleItemId}.barcodes" => 'Scan each serialized item being returned.',
                        ]);
                    }
                    $soldBarcodes = ProductBarcode::query()
                        ->where('business_id', $business->id)
                        ->where('product_id', $product->id)
                        ->where('sale_item_id', $item->id)
                        ->where('status', 'sold')
                        ->whereIn('barcode', $barcodes)
                        ->lockForUpdate()
                        ->get();
                    if ($soldBarcodes->count() !== $quantity) {
                        throw ValidationException::withMessages([
                            "items.{$saleItemId}.barcodes" => 'One or more scanned serial numbers were not sold on this receipt or were already returned.',
                        ]);
                    }
                } elseif ($barcodes !== []) {
                    throw ValidationException::withMessages([
                        "items.{$saleItemId}.barcodes" => 'Serial numbers can only be returned for serialized products.',
                    ]);
                }

                $amountCents = $this->refundableAmountCents($record, $item, $priorQuantity + $quantity)
                    - $this->refundableAmountCents($record, $item, $priorQuantity);
                if ($amountCents <= 0) {
                    throw ValidationException::withMessages(['items' => 'The selected items have no refundable balance.']);
                }
                $totalCents += $amountCents;
                $lines[] = [
                    'item' => $item,
                    'product' => $product,
                    'quantity' => $quantity,
                    'barcodes' => $barcodes,
                    'amount_cents' => $amountCents,
                    'prior_quantity' => $priorQuantity,
                ];
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
                    'cash_register' => 'Open the cash register before recording a cash refund.',
                ]);
            }
            $expectedCashCents = $this->toCents((string) $session->expected_cash);
            if ($totalCents > $expectedCashCents) {
                throw ValidationException::withMessages([
                    'cash_register' => 'The register does not have enough expected cash to record this refund.',
                ]);
            }

            $refund = Refund::create([
                'business_id' => $business->id,
                'sale_id' => $record->id,
                'processed_by' => $request->user()->id,
                'reference_number' => 'RF-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4))),
                'status' => 'completed',
                'amount' => $this->fromCents($totalCents),
                'reason' => $validated['reason'],
            ]);

            foreach ($lines as $line) {
                /** @var SaleItem $item */
                $item = $line['item'];
                /** @var Product $product */
                $product = $line['product'];
                $quantity = $line['quantity'];
                $amountCents = $line['amount_cents'];
                $refund->items()->create([
                    'sale_item_id' => $item->id,
                    'quantity' => $quantity,
                    'unit_amount' => number_format($amountCents / $quantity / 100, 4, '.', ''),
                    'line_amount' => $this->fromCents($amountCents),
                ]);

                if ($product->product_type !== 'goods') {
                    continue;
                }
                $stock = InventoryStock::query()
                    ->where('business_id', $business->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $before = $stock->quantity_on_hand;
                if ($before + $quantity > 2147483647) {
                    throw ValidationException::withMessages([
                        'items' => "Returning {$product->name} would exceed the supported stock quantity.",
                    ]);
                }
                $stock->increment('quantity_on_hand', $quantity);
                if ($product->batch_tracking_enabled) {
                    $this->restoreBatchAllocations($business->id, $item, $quantity, $line['prior_quantity']);
                }
                if ($product->barcode_tracking_enabled) {
                    ProductBarcode::query()
                        ->where('business_id', $business->id)
                        ->where('product_id', $product->id)
                        ->where('sale_item_id', $item->id)
                        ->whereIn('barcode', $line['barcodes'])
                        ->where('status', 'sold')
                        ->update(['status' => 'in_stock', 'sale_item_id' => null]);
                }
                StockMovement::create([
                    'business_id' => $business->id,
                    'inventory_stock_id' => $stock->id,
                    'product_id' => $product->id,
                    'created_by' => $request->user()->id,
                    'type' => 'refund',
                    'quantity_change' => $quantity,
                    'quantity_before' => $before,
                    'quantity_after' => $before + $quantity,
                    'reason' => 'Refund '.$refund->reference_number,
                ]);
            }

            DB::table('cash_register_sessions')->where('id', $session->id)->update([
                'expected_cash' => $this->fromCents($expectedCashCents - $totalCents),
                'updated_at' => now(),
            ]);
            DB::table('cash_movements')->insert([
                'business_id' => $business->id,
                'cash_register_session_id' => $session->id,
                'created_by' => $request->user()->id,
                'type' => 'refund',
                'amount' => $this->fromCents($totalCents),
                'reason' => 'Refund '.$refund->reference_number,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'business_id' => $business->id,
                'user_id' => $request->user()->id,
                'action' => 'sale.refunded',
                'entity_type' => Sale::class,
                'entity_id' => (string) $record->id,
                'new_values' => json_encode([
                    'refund_id' => $refund->id,
                    'reference_number' => $refund->reference_number,
                    'amount' => $refund->amount,
                    'reason' => $refund->reason,
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 65535),
                'created_at' => now(),
            ]);

            return $refund->load('items');
        });

        return response()->json(['data' => [
            ...$refund->only(['id', 'sale_id', 'reference_number', 'status', 'amount', 'reason', 'created_at']),
            'items' => $refund->items->map(fn ($item): array => $item->only([
                'sale_item_id', 'quantity', 'unit_amount', 'line_amount',
            ])),
        ]], 201);
    }

    private function restoreBatchAllocations(string $businessId, SaleItem $item, int $quantity, int $previouslyRefunded): void
    {
        $allocations = $item->batches()
            ->with('batch')
            ->lockForUpdate()
            ->get()
            ->sortBy(fn ($allocation) => $allocation->batch->expires_on);
        $remaining = $quantity;
        foreach ($allocations as $allocation) {
            $alreadyRestored = min($allocation->quantity, max(0, $previouslyRefunded));
            $previouslyRefunded -= $alreadyRestored;
            $availableToRestore = $allocation->quantity - $alreadyRestored;
            $restored = min($remaining, $availableToRestore);
            if ($restored > 0) {
                InventoryBatch::query()
                    ->where('business_id', $businessId)
                    ->whereKey($allocation->inventory_batch_id)
                    ->lockForUpdate()
                    ->firstOrFail()
                    ->increment('quantity_remaining', $restored);
                $remaining -= $restored;
            }
        }
        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'items' => 'The original batch allocation is incomplete; this refund cannot safely restore batch stock.',
            ]);
        }
    }

    private function refundableAmountCents(Sale $sale, SaleItem $item, int $cumulativeQuantity): int
    {
        if ($cumulativeQuantity === 0) {
            return 0;
        }
        $lineCents = $this->toCents((string) $item->line_total);
        $subtotalCents = $this->toCents((string) $sale->subtotal);
        $discountCents = $subtotalCents > 0
            ? (int) round($this->toCents((string) $sale->discount_amount) * $lineCents / $subtotalCents)
            : 0;
        $netCents = $lineCents - $discountCents;
        $saleTaxCents = $this->toCents((string) $sale->tax_amount);
        $saleBaseCents = $subtotalCents - $this->toCents((string) $sale->discount_amount);
        if ($saleTaxCents > 0 && $this->toCents((string) $sale->total) > $saleBaseCents) {
            $netCents += (int) round($saleTaxCents * $netCents / max(1, $saleBaseCents));
        }

        return (int) round($netCents * $cumulativeQuantity / $item->quantity);
    }

    private function toCents(string $amount): int
    {
        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $units * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
