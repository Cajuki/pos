<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashRegisterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $this->defaultRegister($business);
        $registers = CashRegister::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (CashRegister $register): array => $this->registerData($register));

        return response()->json(['data' => $registers]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $validated = $request->validate([
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')->where('business_id', $business->id)],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('cash_registers', 'code')->where('business_id', $business->id)],
        ]);
        $register = CashRegister::create([
            'business_id' => $business->id,
            'branch_id' => $validated['branch_id'] ?? Branch::query()->where('business_id', $business->id)->where('is_default', true)->value('id'),
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'is_active' => true,
        ]);

        return response()->json(['data' => $this->registerData($register)], 201);
    }

    public function current(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'register_id' => ['sometimes', 'integer', Rule::exists('cash_registers', 'id')->where('business_id', $business->id)],
        ]);
        $register = isset($validated['register_id'])
            ? CashRegister::query()->where('business_id', $business->id)->where('is_active', true)->findOrFail($validated['register_id'])
            : $this->defaultRegister($business);

        return response()->json(['data' => $this->registerData($register)]);
    }

    public function open(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'register_id' => ['sometimes', 'integer', Rule::exists('cash_registers', 'id')->where('business_id', $business->id)],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
        ]);
        $registerId = $validated['register_id'] ?? $this->defaultRegister($business)->id;
        $openingBalance = $this->fromCents($this->toCents((string) $validated['opening_balance']));

        $session = DB::transaction(function () use ($business, $request, $registerId, $openingBalance): CashRegisterSession {
            $register = CashRegister::query()
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($registerId);
            $existingSession = CashRegisterSession::query()
                ->where('business_id', $business->id)
                ->where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existingSession) {
                throw ValidationException::withMessages(['cash_register' => 'Cash register is already open.']);
            }

            $session = CashRegisterSession::create([
                'business_id' => $business->id,
                'cash_register_id' => $register->id,
                'user_id' => $request->user()->id,
                'status' => 'open',
                'opening_balance' => $openingBalance,
                'expected_cash' => $openingBalance,
                'opened_at' => now(),
            ]);
            $this->writeSessionAudit($request, $business, $session, 'cash_register.session_opened', null, [
                'cash_register_id' => $register->id,
                'opening_balance' => $session->opening_balance,
            ]);

            return $session;
        });

        return response()->json(['data' => $session->load(['user', 'register'])], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'register_id' => ['sometimes', 'integer', Rule::exists('cash_registers', 'id')->where('business_id', $business->id)],
            'actual_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'closing_notes' => ['nullable', 'string', 'max:500'],
        ]);
        $registerId = $validated['register_id'] ?? $this->defaultRegister($business)->id;
        $actualCash = $this->fromCents($this->toCents((string) $validated['actual_cash']));

        $session = DB::transaction(function () use ($business, $request, $validated, $registerId, $actualCash): CashRegisterSession {
            $register = CashRegister::query()
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->findOrFail($registerId);
            $session = CashRegisterSession::query()
                ->where('business_id', $business->id)
                ->where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw ValidationException::withMessages(['cash_register' => 'No open session found for this till.']);
            }

            $expectedCash = $session->expected_cash;
            $difference = $this->fromCents($this->toCents($actualCash) - $this->toCents((string) $expectedCash));
            $session->update([
                'status' => 'closed',
                'actual_cash' => $actualCash,
                'difference' => $difference,
                'closed_at' => now(),
                'closing_notes' => $validated['closing_notes'] ?? null,
            ]);
            $this->writeSessionAudit(
                $request,
                $business,
                $session,
                'cash_register.session_closed',
                ['expected_cash' => $expectedCash, 'status' => 'open'],
                ['actual_cash' => $session->actual_cash, 'difference' => $session->difference, 'status' => 'closed'],
            );

            return $session;
        });

        return response()->json(['data' => $session->load(['user', 'register'])]);
    }

    private function defaultRegister(Business $business): CashRegister
    {
        return DB::transaction(function () use ($business): CashRegister {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();

            return CashRegister::firstOrCreate(
                ['business_id' => $business->id, 'code' => 'REG-01'],
                [
                    'branch_id' => Branch::firstOrCreate(
                        ['business_id' => $business->id, 'code' => 'MAIN'],
                        ['name' => 'Main Branch', 'is_default' => true, 'is_active' => true],
                    )->id,
                    'name' => 'Main Counter Register',
                    'is_active' => true,
                ],
            );
        });
    }

    /** @param array<string, mixed>|null $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function writeSessionAudit(
        Request $request,
        Business $business,
        CashRegisterSession $session,
        string $action,
        ?array $oldValues,
        array $newValues,
    ): void {
        DB::table('audit_logs')->insert([
            'business_id' => $business->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => CashRegisterSession::class,
            'entity_id' => (string) $session->id,
            'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($newValues, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 65535),
            'created_at' => now(),
        ]);
    }

    private function toCents(string $amount): int
    {
        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $units * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function fromCents(int $amount): string
    {
        $absoluteAmount = abs($amount);
        $sign = $amount < 0 ? '-' : '';

        return $sign.intdiv($absoluteAmount, 100).'.'.str_pad((string) ($absoluteAmount % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function registerData(CashRegister $register): array
    {
        $currentSession = CashRegisterSession::query()
            ->where('business_id', $register->business_id)
            ->where('cash_register_id', $register->id)
            ->where('status', 'open')
            ->with('user')
            ->latest('opened_at')
            ->first();

        return [
            'register' => $register,
            'current_session' => $currentSession,
            'is_open' => (bool) $currentSession,
        ];
    }
}
