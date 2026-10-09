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
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')->where('business_id', $business->id),
                Rule::unique('product_barcodes', 'barcode')->where('business_id', $business->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit_price' => ['required', 'integer', 'min:0'],
            'cost_price' => ['nullable', 'integer', 'min:0'],
            'quantity_on_hand' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'reorder_level' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $product = DB::transaction(function () use ($business, $request, $validated): Product {
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
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')->where('business_id', $business->id)->ignore($record->id),
                Rule::unique('product_barcodes', 'barcode')->where('business_id', $business->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'unit_price' => ['sometimes', 'required', 'integer', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

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
            ...$product->only(['id', 'sku', 'barcode', 'barcode_tracking_enabled', 'name', 'category', 'description', 'unit_price', 'cost_price', 'reorder_level', 'is_active']),
            'quantity_on_hand' => $product->inventoryStock?->quantity_on_hand ?? 0,
        ];
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
