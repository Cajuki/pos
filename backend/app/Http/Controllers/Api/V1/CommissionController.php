<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'staff_user_id' => ['sometimes', 'integer', Rule::exists('business_user', 'user_id')->where('business_id', $business->id)],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'paid', 'reversed'])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);
        $query = CommissionEntry::query()
            ->where('business_id', $business->id)
            ->with(['staff:id,name', 'staffProfile:id,name', 'sale:id,receipt_number,created_at', 'saleItem:id,sale_id,product_name,quantity,line_total']);
        $entries = (clone $query)
            ->when(isset($validated['staff_user_id']), fn ($builder) => $builder->where('staff_user_id', $validated['staff_user_id']))
            ->when(isset($validated['status']), fn ($builder) => $builder->where('status', $validated['status']))
            ->when(isset($validated['from']), fn ($builder) => $builder->whereDate('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($builder) => $builder->whereDate('created_at', '<=', $validated['to']))
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => collect($entries->items())->map(function (CommissionEntry $entry): array {
                $data = $entry->toArray();
                $data['staff'] = $entry->staff ? ['id' => $entry->staff->id, 'name' => $entry->staff->name]
                    : ($entry->staffProfile ? ['id' => $entry->staffProfile->id, 'name' => $entry->staffProfile->name] : null);

                return $data;
            }),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
                'summary' => [
                    'earned' => $this->formatAmount((string) (clone $query)->sum('earned_amount')),
                    'reversed' => $this->formatAmount((string) (clone $query)->sum('reversed_amount')),
                    'pending' => $this->formatAmount((string) (clone $query)->where('status', 'pending')->sum(DB::raw('earned_amount - reversed_amount'))),
                    'approved' => $this->formatAmount((string) (clone $query)->where('status', 'approved')->sum(DB::raw('earned_amount - reversed_amount'))),
                    'paid' => $this->formatAmount((string) (clone $query)->where('status', 'paid')->sum('earned_amount')),
                ],
            ],
        ]);
    }

    public function updateStatus(Request $request, int $commission): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'paid'])],
            'payout_reference' => ['required_if:status,paid', 'nullable', 'string', 'max:120'],
        ]);

        $entry = DB::transaction(function () use ($business, $commission, $request, $validated): CommissionEntry {
            $entry = CommissionEntry::query()
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->findOrFail($commission);
            $allowed = $entry->status === 'pending' && $validated['status'] === 'approved'
                || $entry->status === 'approved' && $validated['status'] === 'paid';
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'This commission cannot move to the requested state.']);
            }
            if ((float) $entry->earned_amount <= (float) $entry->reversed_amount) {
                throw ValidationException::withMessages(['status' => 'A fully refunded commission cannot be approved or paid.']);
            }
            $oldValues = ['status' => $entry->status];
            $entry->update([
                'status' => $validated['status'],
                'approved_by' => $validated['status'] === 'approved' ? $request->user()->id : $entry->approved_by,
                'approved_at' => $validated['status'] === 'approved' ? now() : $entry->approved_at,
                'paid_at' => $validated['status'] === 'paid' ? now() : $entry->paid_at,
                'payout_reference' => $validated['status'] === 'paid' ? $validated['payout_reference'] : $entry->payout_reference,
            ]);
            DB::table('audit_logs')->insert([
                'business_id' => $business->id,
                'user_id' => $request->user()->id,
                'action' => 'commission.status_changed',
                'entity_type' => CommissionEntry::class,
                'entity_id' => (string) $entry->id,
                'old_values' => json_encode($oldValues, JSON_THROW_ON_ERROR),
                'new_values' => json_encode([
                    'status' => $entry->status,
                    'payout_reference' => $entry->payout_reference,
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 65535),
                'created_at' => now(),
            ]);

            return $entry->load(['staff:id,name', 'staffProfile:id,name', 'sale:id,receipt_number', 'saleItem:id,product_name,quantity,line_total']);
        });

        return response()->json(['data' => $entry]);
    }

    private function formatAmount(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $normalized = ltrim($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $fraction = preg_replace('/\D/', '', $fraction) ?: '';

        return ($negative ? '-' : '').$whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
