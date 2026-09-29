<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashRegisterController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $register = CashRegister::firstOrCreate(
            ['business_id' => $business->id, 'code' => 'REG-01'],
            ['name' => 'Main Counter Register', 'is_active' => true]
        );

        $currentSession = CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->where('cash_register_id', $register->id)
            ->where('status', 'open')
            ->with(['user', 'register'])
            ->latest('opened_at')
            ->first();

        return response()->json([
            'data' => [
                'register' => $register,
                'current_session' => $currentSession,
                'is_open' => (bool) $currentSession,
            ],
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'opening_balance' => ['required', 'numeric', 'min:0'],
        ]);

        $register = CashRegister::firstOrCreate(
            ['business_id' => $business->id, 'code' => 'REG-01'],
            ['name' => 'Main Counter Register', 'is_active' => true]
        );

        $existingSession = CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->where('cash_register_id', $register->id)
            ->where('status', 'open')
            ->first();

        if ($existingSession) {
            return response()->json(['message' => 'Cash register is already open.', 'data' => $existingSession], 422);
        }

        $session = CashRegisterSession::create([
            'business_id' => $business->id,
            'cash_register_id' => $register->id,
            'user_id' => $request->user()->id,
            'status' => 'open',
            'opening_balance' => $validated['opening_balance'],
            'expected_cash' => $validated['opening_balance'],
            'opened_at' => now(),
        ]);

        return response()->json(['data' => $session->load('user')], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $register = CashRegister::firstOrCreate(
            ['business_id' => $business->id, 'code' => 'REG-01'],
            ['name' => 'Main Counter Register', 'is_active' => true]
        );

        $session = CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->where('cash_register_id', $register->id)
            ->where('status', 'open')
            ->first();

        if (! $session) {
            return response()->json(['message' => 'No open session found.'], 404);
        }

        $difference = $validated['actual_cash'] - $session->expected_cash;

        $session->update([
            'status' => 'closed',
            'actual_cash' => $validated['actual_cash'],
            'difference' => $difference,
            'closed_at' => now(),
            'closing_notes' => $validated['closing_notes'] ?? null,
        ]);

        return response()->json(['data' => $session]);
    }
}

