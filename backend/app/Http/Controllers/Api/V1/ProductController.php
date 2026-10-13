<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'barcode' => ['sometimes', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $products = Product::query()
            ->where('business_id', $business->id)
            ->with('inventoryStock')
            ->when(isset($validated['barcode']), fn (Builder $query) => $query->where('barcode', $validated['barcode']))
            ->when(! isset($validated['barcode']) && isset($validated['search']), function (Builder $query) use ($validated): void {
                $query->where(function (Builder $query) use ($validated): void {
                    $query->where('name', 'like', '%'.$validated['search'].'%')
                        ->orWhere('sku', 'like', '%'.$validated['search'].'%')
                        ->orWhere('barcode', 'like', '%'.$validated['search'].'%');
                });
            })
            ->when(array_key_exists('active', $validated), fn (Builder $query) => $query->where('is_active', $validated['active']))
            ->orderBy('name')
            ->paginate(25);

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $product): array => $this->productData($product)),
            'meta' => ['current_page' => $products->currentPage(), 'last_page' => $products->lastPage(), 'total' => $products->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('products', 'sku')->where('business_id', $business->id)],
            'barcode' => [
                Rule::prohibitedIf($request->boolean('barcode_tracking_enabled')),
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')->where('business_id', $business->id),
                Rule::unique('product_barcodes', 'barcode')->where('business_id', $business->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'product_type' => ['sometimes', Rule::in(['goods', 'service'])],
            'service_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'service_buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'commission_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'commission_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'variant_attributes' => ['sometimes', 'nullable', 'array', 'max:10'],
            'variant_attributes.*' => ['required', 'string', 'max:120'],
            'bulk_pricing' => ['sometimes', 'nullable', 'array', 'max:20'],
            'bulk_pricing.*.minimum_quantity' => ['required', 'integer', 'min:2', 'max:100000'],
            'bulk_pricing.*.unit_price' => ['required', 'integer', 'min:0'],
            'batch_tracking_enabled' => ['sometimes', 'boolean'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'active_ingredient' => ['nullable', 'string', 'max:255'],
            'strength' => ['nullable', 'string', 'max:100'],
            'dosage_form' => ['nullable', 'string', 'max:100'],
            'pack_size' => ['nullable', 'string', 'max:100'],
            'unit_of_sale' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'medicine_classification' => ['nullable', Rule::in(['otc', 'prescription', 'controlled'])],
            'prescription_required' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit_price' => ['required', 'integer', 'min:0'],
            'cost_price' => ['nullable', 'integer', 'min:0'],
            'quantity_on_hand' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'reorder_level' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'barcode_tracking_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validated['product_type'] ??= 'goods';
        $enabledModules = $business->posSettings()['enabled_modules'];
        if (! empty($validated['variant_attributes']) && ! in_array('variants', $enabledModules, true)) {
            throw ValidationException::withMessages(['variant_attributes' => 'Enable the product variants module for this business first.']);
        }
        if (! empty($validated['bulk_pricing']) && ! in_array('wholesale', $enabledModules, true)) {
            throw ValidationException::withMessages(['bulk_pricing' => 'Enable the wholesale pricing module for this business first.']);
        }
        if (($validated['batch_tracking_enabled'] ?? false) && ! in_array('pharmacy', $enabledModules, true)) {
            throw ValidationException::withMessages(['batch_tracking_enabled' => 'Enable the pharmacy inventory module for this business first.']);
        }
        $this->validateMedicineFields($validated, $enabledModules);
        $this->validateCommissionFields($validated);
        $this->validateBulkPricing($validated['bulk_pricing'] ?? [], $validated['unit_price']);

        if ($validated['product_type'] === 'service') {
            if (isset($validated['barcode']) || ($validated['barcode_tracking_enabled'] ?? false)) {
                throw ValidationException::withMessages([
                    'barcode' => 'Services are non-stock items and cannot use product or serialized barcodes.',
                ]);
            }
            if (($validated['quantity_on_hand'] ?? 0) > 0) {
                throw ValidationException::withMessages([
                    'quantity_on_hand' => 'Services do not have stock quantities.',
                ]);
            }
            unset($validated['barcode_tracking_enabled']);
            unset($validated['batch_tracking_enabled']);
            $validated['service_duration_minutes'] ??= 30;
            $validated['service_buffer_minutes'] ??= 0;
        } elseif (isset($validated['service_duration_minutes'])) {
            throw ValidationException::withMessages([
                'service_duration_minutes' => 'Duration can only be set for a service.',
            ]);
        } elseif (isset($validated['service_buffer_minutes'])) {
            throw ValidationException::withMessages([
                'service_buffer_minutes' => 'Buffer time can only be set for a service.',
            ]);
        }

        if (($validated['barcode_tracking_enabled'] ?? false) && ($validated['quantity_on_hand'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'quantity_on_hand' => 'Receive individually tracked stock by scanning each unit barcode.',
            ]);
        }
        if (($validated['batch_tracking_enabled'] ?? false) && ($validated['quantity_on_hand'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'quantity_on_hand' => 'Receive batch-tracked inventory through the batch receiving workflow.',
            ]);
        }
        if (($validated['batch_tracking_enabled'] ?? false) && ($validated['barcode_tracking_enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'batch_tracking_enabled' => 'Batch tracking and serialized barcode tracking cannot be enabled together.',
            ]);
        }

        $product = DB::transaction(function () use ($business, $request, $validated): Product {
            $business->newQuery()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();
            $subscription = $business->activeSubscription()->with('plan')->first();
            $productLimit = $subscription?->plan?->limits['products'] ?? null;
            if ($productLimit !== null && Product::query()->where('business_id', $business->id)->count() >= $productLimit) {
                throw ValidationException::withMessages([
                    'products' => "This business has reached its {$productLimit}-product subscription limit.",
                ]);
            }

            $quantityOnHand = $validated['quantity_on_hand'] ?? 0;
            unset($validated['quantity_on_hand']);

            $product = Product::create([...$validated, 'business_id' => $business->id]);
            $stock = InventoryStock::create([
                'business_id' => $business->id,
                'product_id' => $product->id,
                'quantity_on_hand' => $quantityOnHand,
            ]);

            if ($quantityOnHand > 0) {
                StockMovement::create([
                    'business_id' => $business->id,
                    'inventory_stock_id' => $stock->id,
                    'product_id' => $product->id,
                    'created_by' => $request->user()->id,
                    'type' => 'adjustment',
                    'quantity_change' => $quantityOnHand,
                    'quantity_before' => 0,
                    'quantity_after' => $quantityOnHand,
                    'reason' => 'Opening stock',
                ]);
            }

            return $product;
        });

        return response()->json(['data' => $this->productData($product->load('inventoryStock'))], 201);
    }

    public function show(Request $request, int $product): JsonResponse
    {
        $record = $this->findProduct($request, $product);

        return response()->json(['data' => $this->productData($record->load('inventoryStock'))]);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $business = $request->attributes->get('business');
        $record = $this->findProduct($request, $product);
        $validated = $request->validate([
            'sku' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('products', 'sku')->where('business_id', $business->id)->ignore($record->id)],
            'barcode' => [
                'sometimes',
                Rule::prohibitedIf($record->barcode_tracking_enabled),
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')->where('business_id', $business->id)->ignore($record->id),
                Rule::unique('product_barcodes', 'barcode')->where('business_id', $business->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'product_type' => ['sometimes', Rule::in(['goods', 'service'])],
            'service_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'service_buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'commission_type' => ['sometimes', 'nullable', Rule::in(['percentage', 'fixed'])],
            'commission_value' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'variant_attributes' => ['sometimes', 'nullable', 'array', 'max:10'],
            'variant_attributes.*' => ['required', 'string', 'max:120'],
            'bulk_pricing' => ['sometimes', 'nullable', 'array', 'max:20'],
            'bulk_pricing.*.minimum_quantity' => ['required', 'integer', 'min:2', 'max:100000'],
            'bulk_pricing.*.unit_price' => ['required', 'integer', 'min:0'],
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'generic_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active_ingredient' => ['sometimes', 'nullable', 'string', 'max:255'],
            'strength' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dosage_form' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pack_size' => ['sometimes', 'nullable', 'string', 'max:100'],
            'unit_of_sale' => ['sometimes', 'nullable', 'string', 'max:100'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'medicine_classification' => ['sometimes', 'nullable', Rule::in(['otc', 'prescription', 'controlled'])],
            'prescription_required' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'unit_price' => ['sometimes', 'required', 'integer', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $nextType = $validated['product_type'] ?? $record->product_type;
        $enabledModules = $business->posSettings()['enabled_modules'];
        $this->validateMedicineFields($validated, $enabledModules, $record);
        $this->validateCommissionFields($validated, $record);
        if (array_key_exists('variant_attributes', $validated)
            && ! empty($validated['variant_attributes'])
            && ! in_array('variants', $enabledModules, true)) {
            throw ValidationException::withMessages(['variant_attributes' => 'Enable the product variants module for this business first.']);
        }
        if (array_key_exists('bulk_pricing', $validated)
            && ! empty($validated['bulk_pricing'])
            && ! in_array('wholesale', $enabledModules, true)) {
            throw ValidationException::withMessages(['bulk_pricing' => 'Enable the wholesale pricing module for this business first.']);
        }
        if (array_key_exists('bulk_pricing', $validated)) {
            $this->validateBulkPricing($validated['bulk_pricing'] ?? [], $validated['unit_price'] ?? $record->unit_price);
        }
        if ($nextType === 'service') {
            if ($record->inventoryStock?->quantity_on_hand > 0
                || $record->barcode_tracking_enabled
                || $record->trackedBarcodes()->exists()
                || ($record->barcode && ! array_key_exists('barcode', $validated))
                || isset($validated['barcode'])) {
                throw ValidationException::withMessages([
                    'product_type' => 'A stocked or previously barcoded product cannot be converted to a service.',
                ]);
            }
            if (isset($validated['barcode_tracking_enabled']) || (isset($validated['quantity_on_hand']) && $validated['quantity_on_hand'] > 0)) {
                throw ValidationException::withMessages([
                    'product_type' => 'Services cannot use serialized barcode tracking or stock quantities.',
                ]);
            }
            unset($validated['barcode_tracking_enabled']);
            $validated['barcode'] = null;
            $validated['service_duration_minutes'] ??= $record->service_duration_minutes ?? 30;
            $validated['service_buffer_minutes'] ??= $record->service_buffer_minutes ?? 0;
        } elseif (isset($validated['service_duration_minutes'])) {
            throw ValidationException::withMessages([
                'service_duration_minutes' => 'Duration can only be set for a service.',
            ]);
        } elseif (isset($validated['service_buffer_minutes'])) {
            throw ValidationException::withMessages([
                'service_buffer_minutes' => 'Buffer time can only be set for a service.',
            ]);
        }

        $record->update($validated);

        return response()->json(['data' => $this->productData($record->load('inventoryStock'))]);
    }

    private function findProduct(Request $request, int $product): Product
    {
        $business = $request->attributes->get('business');

        return Product::query()->where('business_id', $business->id)->findOrFail($product);
    }

    private function productData(Product $product): array
    {
        return [
            ...$product->only(['id', 'sku', 'barcode', 'barcode_tracking_enabled', 'product_type', 'service_duration_minutes', 'service_buffer_minutes', 'commission_type', 'commission_value', 'variant_attributes', 'bulk_pricing', 'batch_tracking_enabled', 'brand_name', 'generic_name', 'active_ingredient', 'strength', 'dosage_form', 'pack_size', 'unit_of_sale', 'manufacturer', 'registration_number', 'medicine_classification', 'prescription_required', 'name', 'category', 'description', 'unit_price', 'cost_price', 'reorder_level', 'is_active']),
            'quantity_on_hand' => $product->inventoryStock?->quantity_on_hand ?? 0,
            'quantity_available' => max(
                0,
                ($product->inventoryStock?->quantity_on_hand ?? 0) - ($product->inventoryStock?->quantity_reserved ?? 0),
            ),
        ];
    }

    private function validateBulkPricing(array $tiers, string|int $basePrice): void
    {
        $previousQuantity = 1;
        $basePrice = (int) $basePrice;
        foreach ($tiers as $tier) {
            if ($tier['minimum_quantity'] <= $previousQuantity) {
                throw ValidationException::withMessages([
                    'bulk_pricing' => 'Bulk price quantities must be listed in strictly increasing order.',
                ]);
            }
            if ($tier['unit_price'] > $basePrice) {
                throw ValidationException::withMessages([
                    'bulk_pricing' => 'A bulk unit price cannot be greater than the standard price.',
                ]);
            }
            $previousQuantity = $tier['minimum_quantity'];
        }
    }

    private function validateMedicineFields(array $validated, array $enabledModules, ?Product $record = null): void
    {
        $medicineFields = [
            'brand_name', 'generic_name', 'active_ingredient', 'strength', 'dosage_form', 'pack_size',
            'unit_of_sale', 'manufacturer', 'registration_number', 'medicine_classification', 'prescription_required',
        ];
        if (array_intersect($medicineFields, array_keys($validated)) !== []
            && ! in_array('pharmacy', $enabledModules, true)) {
            throw ValidationException::withMessages([
                'medicine_classification' => 'Enable the pharmacy module before setting medicine details.',
            ]);
        }
        $classification = $validated['medicine_classification'] ?? $record?->medicine_classification;
        $prescriptionRequired = $validated['prescription_required'] ?? $record?->prescription_required ?? false;
        if (in_array($classification, ['prescription', 'controlled'], true) && ! $prescriptionRequired) {
            throw ValidationException::withMessages([
                'prescription_required' => 'Prescription and controlled medicines must require prescription verification.',
            ]);
        }
    }

    private function validateCommissionFields(array $validated, ?Product $record = null): void
    {
        $type = array_key_exists('commission_type', $validated) ? $validated['commission_type'] : $record?->commission_type;
        $value = array_key_exists('commission_value', $validated) ? $validated['commission_value'] : $record?->commission_value;
        if (($type === null) !== ($value === null)) {
            throw ValidationException::withMessages([
                'commission_type' => 'Set both a commission type and value, or clear both to disable commission.',
            ]);
        }
        if ($type === 'percentage' && (float) $value > 100) {
            throw ValidationException::withMessages(['commission_value' => 'Percentage commission cannot exceed 100%.']);
        }
    }

    public function barcode(Request $request, string $barcode): JsonResponse
    {
        $business = $request->attributes->get('business');
        $trackedBarcode = ProductBarcode::query()
            ->where('business_id', $business->id)
            ->where('barcode', $barcode)
            ->with(['product.inventoryStock'])
            ->first();

        if ($trackedBarcode) {
            return response()->json(['data' => [
                'product' => $this->productData($trackedBarcode->product),
                'tracked_barcode' => $trackedBarcode->status === 'in_stock' ? $trackedBarcode->barcode : null,
                'status' => $trackedBarcode->status,
            ]]);
        }

        $product = Product::query()
            ->where('business_id', $business->id)
            ->where('barcode', $barcode)
            ->where('is_active', true)
            ->with('inventoryStock')
            ->first();

        return response()->json(['data' => $product ? [
            'product' => $this->productData($product),
            'tracked_barcode' => null,
            'status' => 'product',
        ] : null]);
    }
}
