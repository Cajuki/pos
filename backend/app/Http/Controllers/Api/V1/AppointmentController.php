<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Product;
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
        $appointments = Appointment::query()
            ->where('business_id', $business->id)
            ->with(['customer:id,name,phone', 'service:id,name,unit_price', 'staff:id,name'])
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
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $business->id)],
            'service_product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $business->id)->where('product_type', 'service')->where('is_active', true)],
            'staff_user_id' => ['required', 'integer', Rule::exists('business_user', 'user_id')->where('business_id', $business->id)],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment = DB::transaction(function () use ($business, $validated): Appointment {
            $service = Product::query()
                ->where('business_id', $business->id)
                ->where('product_type', 'service')
                ->where('is_active', true)
                ->findOrFail($validated['service_product_id']);
            $staff = User::query()
                ->whereHas('businesses', fn ($query) => $query->whereKey($business->id))
                ->lockForUpdate()
                ->findOrFail($validated['staff_user_id']);
            $startsAt = Carbon::parse($validated['starts_at']);
            $duration = $validated['duration_minutes'] ?? $service->service_duration_minutes ?? 30;
            $endsAt = $startsAt->copy()->addMinutes($duration);

            $hasConflict = Appointment::query()
                ->where('business_id', $business->id)
                ->where('staff_user_id', $staff->id)
                ->whereIn('status', ['booked', 'in_progress'])
                ->where('starts_at', '<', $endsAt)
                ->get(['starts_at', 'duration_minutes'])
                ->contains(fn (Appointment $appointment): bool => $appointment->starts_at->copy()
                    ->addMinutes($appointment->duration_minutes)
                    ->greaterThan($startsAt));

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'staff_user_id' => 'This staff member already has an appointment during that time.',
                ]);
            }

            return Appointment::create([
                'business_id' => $business->id,
                'customer_id' => $validated['customer_id'] ?? null,
                'service_product_id' => $service->id,
                'staff_user_id' => $staff->id,
                'starts_at' => $startsAt,
                'duration_minutes' => $duration,
                'status' => 'booked',
                'notes' => $validated['notes'] ?? null,
            ])->load(['customer:id,name,phone', 'service:id,name,unit_price', 'staff:id,name']);
        });

        return response()->json(['data' => $appointment], 201);
    }

    public function updateStatus(Request $request, int $appointment): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'completed', 'cancelled', 'no_show'])],
        ]);
        $record = Appointment::query()->where('business_id', $business->id)->findOrFail($appointment);
        $allowed = match ($record->status) {
            'booked' => ['in_progress', 'cancelled', 'no_show'],
            'in_progress' => ['completed', 'cancelled'],
            default => [],
        };

        if (! in_array($validated['status'], $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'That appointment status change is not allowed.',
            ]);
        }

        $record->update(['status' => $validated['status']]);

        return response()->json(['data' => $record->load(['customer:id,name,phone', 'service:id,name,unit_price', 'staff:id,name'])]);
    }
}
