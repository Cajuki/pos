<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $branches = Branch::query()
            ->where('business_id', $business->id)
            ->withCount(['warehouses', 'cashRegisters'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $branches]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('branches', 'code')->where('business_id', $business->id)],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $branch = DB::transaction(function () use ($business, $validated): Branch {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            Branch::query()->where('business_id', $business->id)->lockForUpdate()->get();
            $isDefault = ($validated['is_default'] ?? false)
                || ! Branch::query()->where('business_id', $business->id)->exists();
            if ($isDefault) {
                Branch::query()->where('business_id', $business->id)->update(['is_default' => false]);
            }

            return Branch::create([
                'business_id' => $business->id,
                'name' => trim($validated['name']),
                'code' => $validated['code'],
                'address' => $validated['address'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'is_default' => $isDefault,
                'is_active' => true,
            ]);
        });

        return response()->json(['data' => $branch], 201);
    }

    public function update(Request $request, int $branch): JsonResponse
    {
        $business = $request->attributes->get('business');
        $record = Branch::query()->where('business_id', $business->id)->findOrFail($branch);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'code' => ['sometimes', 'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('branches', 'code')->where('business_id', $business->id)->ignore($record->id)],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $record = DB::transaction(function () use ($business, $record, $validated): Branch {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            Branch::query()->where('business_id', $business->id)->lockForUpdate()->get();
            $isDefault = $validated['is_default'] ?? $record->is_default;
            $isActive = $validated['is_active'] ?? $record->is_active;
            if (! $isActive && $isDefault) {
                throw ValidationException::withMessages(['is_active' => 'Choose another default branch before deactivating this branch.']);
            }
            if (array_key_exists('is_default', $validated) && ! $validated['is_default'] && $record->is_default) {
                throw ValidationException::withMessages(['is_default' => 'Choose another default branch before clearing this branch as default.']);
            }
            if ($isDefault) {
                Branch::query()->where('business_id', $business->id)->where('id', '<>', $record->id)->update(['is_default' => false]);
            }
            $record->update($validated);

            return $record->refresh();
        });

        return response()->json(['data' => $record]);
    }
}
