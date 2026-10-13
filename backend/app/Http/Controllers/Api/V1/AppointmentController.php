<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'staff_user_id' => ['sometimes', 'integer', Rule::exists('business_user', 'user_id')->where('business_id', $business->id)],
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')->where('business_id', $business->id)],
        ]);
        $appointments = Appointment::query()
            ->where('business_id', $business->id)
            ->with(['customer:id,name,phone', 'service:id,name,unit_price', 'staff:id,name', 'staffProfile:id,name'])
            ->when(isset($validated['from']), fn ($query) => $query->where('starts_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($query) => $query->where('starts_at', '<=', $validated['to']))
            ->when(isset($validated['staff_user_id']), fn ($query) => $query->where('staff_user_id', $validated['staff_user_id']))
            ->when(isset($validated['branch_id']), fn ($query) => $query->where('branch_id', $validated['branch_id']))
            ->orderBy('starts_at')
            ->paginate(50);

        return response()->json([
            'data' => $appointments->items(),
            'meta' => [
                'current_page' => $appointments->currentPage(),
                'last_page' => $appointments->lastPage(),
                'total' => $appointments->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $this->validatedBooking($request, $business);
        $appointment = $this->saveBooking($business, $validated, $request);

        return response()->json(['data' => $appointment], 201);
    }

    public function update(Request $request, int $appointment): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $this->validatedBooking($request, $business, true);
        $record = Appointment::query()->where('business_id', $business->id)->findOrFail($appointment);
        if (! in_array($record->status, ['booked'], true)) {
            throw ValidationException::withMessages(['appointment' => 'Only booked appointments can be edited or rescheduled.']);
        }

        return response()->json(['data' => $this->saveBooking($business, $validated, $request, $record)]);
    }

    public function checkIn(Request $request, int $appointment): JsonResponse
    {
        $business = $request->attributes->get('business');
        $record = DB::transaction(function () use ($business, $appointment, $request): Appointment {
            $record = Appointment::query()
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->findOrFail($appointment);
            if ($record->status !== 'booked' || $record->checked_in_at) {
                throw ValidationException::withMessages(['appointment' => 'This appointment cannot be checked in.']);
            }
            $record->update(['checked_in_at' => now()]);
            $this->writeAudit($request, $business, 'appointment.checked_in', $record->id, null, [
                'checked_in_at' => $record->checked_in_at->toIso8601String(),
            ]);

            return $record->load(['customer:id,name,phone', 'service:id,name,unit_price', 'staff:id,name', 'staffProfile:id,name']);
        });

        return response()->json(['data' => $record]);
    }

    public function updateStatus(Request $request, int $appointment): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'completed', 'cancelled', 'no_show'])],
        ]);
        $record = DB::transaction(function () use ($business, $appointment, $validated, $request): Appointment {
            $record = Appointment::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($appointment);
            $oldStatus = $record->status;
            $allowed = match ($record->status) {
                'booked' => ['in_progress', 'cancelled', 'no_show'],
                'in_progress' => ['completed', 'cancelled'],
                default => [],
            };
            if (! in_array($validated['status'], $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'That appointment status change is not allowed.']);
            }
            $record->update([
                'status' => $validated['status'],
                'checked_in_at' => $validated['status'] === 'in_progress' ? ($record->checked_in_at ?? now()) : $record->checked_in_at,
            ]);
            $this->writeAudit($request, $business, 'appointment.status_changed', $record->id, [
                'status' => $oldStatus,
            ], [
                'status' => $record->status,
            ]);

            return $record->load(['customer:id,name,phone', 'service:id,name,unit_price', 'staff:id,name', 'staffProfile:id,name']);
        });

        return response()->json(['data' => $record]);
    }

    private function validatedBooking(Request $request, Business $business): array
    {
        $walkIn = $request->boolean('walk_in');
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $business->id)],
            'service_product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $business->id)->where('product_type', 'service')->where('is_active', true)],
            'staff_user_id' => ['required_without:staff_profile_id', 'nullable', 'integer', Rule::exists('business_user', 'user_id')->where('business_id', $business->id)],
            'staff_profile_id' => ['required_without:staff_user_id', 'nullable', 'integer', Rule::exists('staff_profiles', 'id')->where('business_id', $business->id)->where('employment_status', 'active')],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->where('business_id', $business->id)],
            'starts_at' => [$walkIn ? 'nullable' : 'required', 'date', 'after_or_equal:now'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'walk_in' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $validated;
    }

    private function saveBooking(Business $business, array $validated, Request $request, ?Appointment $existing = null): Appointment
    {
        return DB::transaction(function () use ($business, $validated, $request, $existing): Appointment {
            $record = $existing
                ? Appointment::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($existing->id)
                : null;
            if ($record && $record->status !== 'booked') {
                throw ValidationException::withMessages(['appointment' => 'Only booked appointments can be edited or rescheduled.']);
            }
            $oldValues = $record ? [
                'branch_id' => $record->branch_id,
                'service_product_id' => $record->service_product_id,
                'staff_user_id' => $record->staff_user_id,
                'starts_at' => $record->starts_at->toIso8601String(),
            ] : null;
            $profile = isset($validated['staff_profile_id'])
                ? StaffProfile::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($validated['staff_profile_id'])
                : StaffProfile::query()->where('business_id', $business->id)->where('user_id', $validated['staff_user_id'])->first();
            $staffId = $profile?->user_id ?? ($validated['staff_user_id'] ?? null);
            $staff = $staffId ? User::query()
                ->whereHas('businesses', fn ($query) => $query->whereKey($business->id))
                ->lockForUpdate()
                ->findOrFail($staffId) : null;
            if (isset($validated['staff_user_id']) && $profile && $profile->user_id !== (int) $validated['staff_user_id']) {
                throw ValidationException::withMessages(['staff_user_id' => 'The selected staff profile does not belong to that account.']);
            }
            $service = Product::query()
                ->where('business_id', $business->id)
                ->where('product_type', 'service')
                ->where('is_active', true)
                ->findOrFail($validated['service_product_id']);
            $branchId = $this->resolveBranchId($business->id, $validated['branch_id'] ?? $record?->branch_id);
            if ($profile && ($profile->employment_status !== 'active'
                || ! $profile->branches()->whereKey($branchId)->exists()
                || ! $profile->services()->whereKey($service->id)->exists())) {
                throw ValidationException::withMessages(['staff_user_id' => 'This staff member is inactive or is not assigned to this branch and service.']);
            }
            $startsAt = Carbon::parse($validated['starts_at'] ?? now())->setTimezone($business->timezone ?: config('app.timezone'));
            $duration = $validated['duration_minutes'] ?? $service->service_duration_minutes ?? 30;
            $buffer = $service->service_buffer_minutes ?? 0;
            if ($profile && DB::table('staff_leave_records')->where('business_id', $business->id)
                ->where('staff_profile_id', $profile->id)->where('status', 'approved')
                ->where('starts_at', '<', $startsAt->copy()->addMinutes($duration + $buffer))
                ->where('ends_at', '>', $startsAt)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'This staff member is on approved leave during that time.']);
            }
            if ($profile && ! $staff) {
                $this->validateProfileSchedule($business->id, $branchId, $profile->id, $startsAt, $duration + $buffer);
            } elseif ($staff) {
                $this->validateSchedule($business->id, $branchId, $staff->id, $startsAt, $duration + $buffer);
            }

            $conflicts = Appointment::query()
                ->where('business_id', $business->id)
                ->whereIn('status', ['booked', 'in_progress'])
                ->where('starts_at', '<', $startsAt->copy()->addMinutes($duration + $buffer))
                ->where(function ($query) use ($staff, $profile): void {
                    if ($staff) {
                        $query->where('staff_user_id', $staff->id);
                    }
                    if ($profile) {
                        $query->orWhere('staff_profile_id', $profile->id);
                    }
                })
                ->when($record, fn ($query) => $query->where('id', '!=', $record->id))
                ->get(['starts_at', 'duration_minutes', 'buffer_minutes']);
            $hasConflict = $conflicts
                ->contains(fn (Appointment $appointment): bool => $appointment->starts_at->copy()
                    ->addMinutes($appointment->duration_minutes + $appointment->buffer_minutes)
                    ->greaterThan($startsAt));
            if ($hasConflict) {
                throw ValidationException::withMessages(['staff_user_id' => 'This staff member already has an appointment during that time.']);
            }

            $walkIn = (bool) ($validated['walk_in'] ?? false);
            $attributes = [
                'business_id' => $business->id,
                'branch_id' => $branchId,
                'customer_id' => $validated['customer_id'] ?? null,
                'service_product_id' => $service->id,
                'staff_user_id' => $staff?->id,
                'staff_profile_id' => $profile?->id,
                'starts_at' => $startsAt,
                'duration_minutes' => $duration,
                'buffer_minutes' => $buffer,
                'booking_source' => $walkIn ? 'walk_in' : 'appointment',
                'checked_in_at' => $walkIn ? now() : $record?->checked_in_at,
                'status' => 'booked',
                'notes' => $validated['notes'] ?? null,
            ];
            if ($record) {
                $record->update($attributes);
                $record->refresh();
            } else {
                $record = Appointment::create($attributes);
            }
            $this->writeAudit($request, $business, $existing ? 'appointment.rescheduled' : 'appointment.booked', $record->id, $oldValues, [
                'branch_id' => $record->branch_id,
                'service_product_id' => $record->service_product_id,
                'staff_user_id' => $record->staff_user_id,
                'starts_at' => $record->starts_at->toIso8601String(),
                'booking_source' => $record->booking_source,
            ]);

            return $record->load(['customer:id,name,phone', 'service:id,name,unit_price', 'staff:id,name', 'staffProfile:id,name']);
        });
    }

    private function resolveBranchId(string $businessId, ?int $branchId): ?int
    {
        $branches = Branch::query()->where('business_id', $businessId)->where('is_active', true);
        if ($branchId) {
            return $branches->findOrFail($branchId)->id;
        }
        $activeBranches = $branches->get();
        if ($activeBranches->count() > 1) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a branch for this appointment.']);
        }

        return $activeBranches->first()?->id;
    }

    private function validateSchedule(string $businessId, ?int $branchId, int $staffId, Carbon $startsAt, int $duration): void
    {
        if (! $branchId) {
            return;
        }
        $scheduleQuery = DB::table('staff_schedules')
            ->where('business_id', $businessId)
            ->where('staff_user_id', $staffId);
        $hasConfiguredSchedule = (clone $scheduleQuery)->exists();
        if (! $hasConfiguredSchedule) {
            return;
        }
        $schedule = (clone $scheduleQuery)
            ->where('branch_id', $branchId)
            ->where('day_of_week', $startsAt->dayOfWeek)
            ->first();
        $endsAt = $startsAt->copy()->addMinutes($duration);
        if (! $schedule || ! $schedule->is_working || $endsAt->toDateString() !== $startsAt->toDateString()
            || $startsAt->toTimeString() < $schedule->starts_at
            || $endsAt->toTimeString() > $schedule->ends_at) {
            throw ValidationException::withMessages(['starts_at' => 'The appointment must fit within the staff member’s working hours.']);
        }
        if ($schedule->break_starts_at && $schedule->break_ends_at
            && $startsAt->format('H:i:s') < $schedule->break_ends_at
            && $endsAt->format('H:i:s') > $schedule->break_starts_at) {
            throw ValidationException::withMessages(['starts_at' => 'The appointment overlaps the staff member’s break.']);
        }
    }

    private function validateProfileSchedule(string $businessId, ?int $branchId, int $profileId, Carbon $startsAt, int $duration): void
    {
        if (! $branchId) {
            return;
        }
        $scheduleQuery = DB::table('staff_profile_schedules')
            ->where('business_id', $businessId)
            ->where('staff_profile_id', $profileId);
        if (! $scheduleQuery->exists()) {
            return;
        }
        $schedule = $scheduleQuery->where('branch_id', $branchId)
            ->where('day_of_week', $startsAt->dayOfWeek)
            ->first();
        $endsAt = $startsAt->copy()->addMinutes($duration);
        if (! $schedule || ! $schedule->is_working || $endsAt->toDateString() !== $startsAt->toDateString()
            || $startsAt->toTimeString() < $schedule->starts_at
            || $endsAt->toTimeString() > $schedule->ends_at) {
            throw ValidationException::withMessages(['starts_at' => 'The appointment must fit within the staff member’s working hours.']);
        }
        if ($schedule->break_starts_at && $schedule->break_ends_at
            && $startsAt->format('H:i:s') < $schedule->break_ends_at
            && $endsAt->format('H:i:s') > $schedule->break_starts_at) {
            throw ValidationException::withMessages(['starts_at' => 'The appointment overlaps the staff member’s break.']);
        }
    }

    private function writeAudit(Request $request, Business $business, string $action, int $appointmentId, ?array $oldValues, array $newValues): void
    {
        DB::table('audit_logs')->insert([
            'business_id' => $business->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => Appointment::class,
            'entity_id' => (string) $appointmentId,
            'old_values' => $oldValues ? json_encode($oldValues, JSON_THROW_ON_ERROR) : null,
            'new_values' => json_encode($newValues, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 65535),
            'created_at' => now(),
        ]);
    }
}
