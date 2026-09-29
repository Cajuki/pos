<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Product;
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
            'active' => ['sometimes', 'boolean'],
        ]);

        $products = Product::query()
            ->where('business_id', $business->id)
            ->with('inventoryStock')
            ->when(isset($validated['search']), function (Builder $query) use ($validated): void {
                $query->where(function (Builder $query) use ($validated): void {
                    $query->where('name', 'like', '%'.$validated['search'].'%')
                        ->orWhere('sku', 'like', '%'.$validated['search'].'%');
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
            'sku' => ['required', 'string', 'max:64', Rule::unique('products', 'sku')->where('business_id', $business->id)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit_price' => ['required', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
            'cost_price' => ['nullable', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
            'reorder_level' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $product = DB::transaction(function () use ($business, $validated): Product {
            $product = Product::create([...$validated, 'business_id' => $business->id]);
            InventoryStock::create(['business_id' => $business->id, 'product_id' => $product->id]);

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
            'sku' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('products', 'sku')->where('business_id', $business->id)->ignore($record->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'unit_price' => ['sometimes', 'required', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
            'cost_price' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
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
            ...$product->only(['id', 'sku', 'name', 'category', 'description', 'unit_price', 'cost_price', 'reorder_level', 'is_active']),
            'quantity_on_hand' => $product->inventoryStock?->quantity_on_hand ?? 0,
        ];
    }
}
