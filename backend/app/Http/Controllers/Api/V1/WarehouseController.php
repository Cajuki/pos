<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')->where('business_id', $business->id)],
        ]);

        return response()->json(['data' => Warehouse::query()
            ->where('business_id', $business->id)
            ->when(isset($validated['branch_id']), fn ($query) => $query->where('branch_id', $validated['branch_id']))
            ->with('branch:id,name,code')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $validated = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('business_id', $business->id)],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('warehouses', 'code')->where('business_id', $business->id)],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $warehouse = DB::transaction(function () use ($business, $validated): Warehouse {
            $branch = Branch::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($validated['branch_id']);
            $isDefault = ($validated['is_default'] ?? false)
                || ! Warehouse::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->exists();
            if ($isDefault) {
                Warehouse::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->update(['is_default' => false]);
            }

            return Warehouse::create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'name' => trim($validated['name']),
                'code' => $validated['code'],
                'address' => $validated['address'] ?? null,
                'is_default' => $isDefault,
                'is_active' => true,
            ]);
        });

        return response()->json(['data' => $warehouse->load('branch:id,name,code')], 201);
    }
}
