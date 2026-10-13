<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')->where('business_id', $business->id)],
            'staff_user_id' => ['sometimes', 'integer', Rule::exists('business_user', 'user_id')->where('business_id', $business->id)],
        ]);

        $schedules = DB::table('staff_schedules')
            ->where('business_id', $business->id)
            ->when(isset($validated['branch_id']), fn ($query) => $query->where('branch_id', $validated['branch_id']))
            ->when(isset($validated['staff_user_id']), fn ($query) => $query->where('staff_user_id', $validated['staff_user_id']))
            ->orderBy('staff_user_id')
            ->orderBy('branch_id')
            ->orderBy('day_of_week')
            ->get();

        return response()->json(['data' => $schedules]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $business->id)],
            'staff_user_id' => ['required', 'integer', Rule::exists('business_user', 'user_id')->where('business_id', $business->id)],
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'is_working' => ['required', 'boolean'],
            'starts_at' => ['required_if:is_working,true', 'nullable', 'date_format:H:i'],
            'ends_at' => ['required_if:is_working,true', 'nullable', 'date_format:H:i', 'after:starts_at'],
            'break_starts_at' => ['nullable', 'date_format:H:i', 'required_with:break_ends_at'],
            'break_ends_at' => ['nullable', 'date_format:H:i', 'required_with:break_starts_at', 'after:break_starts_at'],
        ]);

        Branch::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->findOrFail($validated['branch_id']);
        User::query()
            ->whereHas('businesses', fn ($query) => $query->whereKey($business->id))
            ->findOrFail($validated['staff_user_id']);

        if ($validated['is_working'] && isset($validated['break_starts_at'])
            && ($validated['break_starts_at'] <= $validated['starts_at']
                || $validated['break_ends_at'] >= $validated['ends_at'])) {
            throw ValidationException::withMessages([
                'break_starts_at' => 'The break must fit inside the configured working hours.',
            ]);
        }

        $schedule = [
            'business_id' => $business->id,
            'branch_id' => $validated['branch_id'],
            'staff_user_id' => $validated['staff_user_id'],
            'day_of_week' => $validated['day_of_week'],
            'is_working' => $validated['is_working'],
            'starts_at' => $validated['is_working'] ? $validated['starts_at'] : '00:00',
            'ends_at' => $validated['is_working'] ? $validated['ends_at'] : '00:00',
            'break_starts_at' => $validated['is_working'] ? ($validated['break_starts_at'] ?? null) : null,
            'break_ends_at' => $validated['is_working'] ? ($validated['break_ends_at'] ?? null) : null,
            'updated_at' => now(),
        ];

        $record = DB::transaction(function () use ($schedule, $business, $validated, $request): object {
            $keys = [
                'business_id' => $business->id,
                'branch_id' => $validated['branch_id'],
                'staff_user_id' => $validated['staff_user_id'],
                'day_of_week' => $validated['day_of_week'],
            ];
            $previous = DB::table('staff_schedules')->where($keys)->lockForUpdate()->first();
            DB::table('staff_schedules')->updateOrInsert($keys, [...$schedule, 'created_at' => now()]);
            $record = DB::table('staff_schedules')->where($keys)->first();
            DB::table('audit_logs')->insert([
                'business_id' => $business->id,
                'user_id' => $request->user()->id,
                'action' => 'staff_schedule.updated',
                'entity_type' => 'staff_schedule',
                'entity_id' => (string) $record->id,
                'old_values' => $previous ? json_encode((array) $previous, JSON_THROW_ON_ERROR) : null,
                'new_values' => json_encode([
                    'branch_id' => $record->branch_id,
                    'staff_user_id' => $record->staff_user_id,
                    'day_of_week' => $record->day_of_week,
                    'is_working' => (bool) $record->is_working,
                    'starts_at' => $record->starts_at,
                    'ends_at' => $record->ends_at,
                    'break_starts_at' => $record->break_starts_at,
                    'break_ends_at' => $record->break_ends_at,
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 65535),
                'created_at' => now(),
            ]);

            return $record;
        });

        return response()->json(['data' => $record], 200);
    }
}
