<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RestaurantOrder;
use App\Models\RestaurantTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RestaurantTableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')->where('business_id', $business->id)],
        ]);

        $tables = RestaurantTable::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->when(isset($validated['branch_id']), fn ($query) => $query->where('branch_id', $validated['branch_id']))
            ->with('branch:id,name,code')
            ->withCount(['orders as open_orders_count' => fn ($query) => $query->whereNotIn('status', ['paid', 'cancelled'])])
            ->orderBy('area')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $tables]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $business->id)],
            'name' => ['required', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:80'],
            'seating_capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        $branch = Branch::query()->where('business_id', $business->id)->where('is_active', true)->findOrFail($validated['branch_id']);
        $table = RestaurantTable::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'name' => trim($validated['name']),
            'area' => isset($validated['area']) ? trim($validated['area']) : null,
            'seating_capacity' => $validated['seating_capacity'] ?? 2,
            'status' => 'available',
            'is_active' => true,
        ]);

        return response()->json(['data' => $table->load('branch:id,name,code')], 201);
    }

    public function update(Request $request, int $table): JsonResponse
    {
        $business = $request->attributes->get('business');
        $record = RestaurantTable::query()->where('business_id', $business->id)->findOrFail($table);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80', Rule::unique('restaurant_tables', 'name')->where('business_id', $business->id)->where('branch_id', $record->branch_id)->ignore($record->id)],
            'area' => ['sometimes', 'nullable', 'string', 'max:80'],
            'seating_capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'status' => ['sometimes', Rule::in(['available', 'reserved', 'cleaning'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $record = DB::transaction(function () use ($business, $record, $validated): RestaurantTable {
            $record = RestaurantTable::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($record->id);
            $hasOpenOrder = RestaurantOrder::query()
                ->where('business_id', $business->id)
                ->where('restaurant_table_id', $record->id)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->exists();

            if ($hasOpenOrder && (isset($validated['status']) || (($validated['is_active'] ?? true) === false))) {
                throw ValidationException::withMessages(['status' => 'Close the active table order before changing this table status.']);
            }
            if (($validated['is_active'] ?? true) === false && ($validated['status'] ?? $record->status) === 'occupied') {
                throw ValidationException::withMessages(['is_active' => 'An occupied table cannot be deactivated.']);
            }

            $record->update($validated);

            return $record->refresh();
        });

        return response()->json(['data' => $record->load('branch:id,name,code')]);
    }
}
