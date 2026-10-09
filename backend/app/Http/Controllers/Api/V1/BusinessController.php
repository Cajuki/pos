<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businesses = $request->user()->businesses()->get()->map(fn (Business $business): array => [
            ...$business->only(['id', 'name', 'currency', 'timezone']),
            'role' => $business->pivot->role,
        ]);

        return response()->json(['data' => $businesses]);
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        $membership = $request->user()->businesses()->whereKey($business->getKey())->firstOrFail();

        return response()->json([
            'data' => [
                ...$business->only(['id', 'name', 'currency', 'timezone']),
                'role' => $membership->pivot->role,
            ],
        ]);
    }

    public function staff(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $this->authorizeStaffManagement($request, $business);

        $staff = $business->users()->get()->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->pivot->role,
        ]);

        return response()->json(['data' => $staff]);
    }

    public function addStaff(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $this->authorizeStaffManagement($request, $business);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/'],
            'role' => ['required', Rule::in(['admin', 'manager', 'cashier', 'inventory'])],
        ]);

        $user = DB::transaction(function () use ($business, $validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);
            $business->users()->attach($user, ['role' => $validated['role']]);

            return $user;
        });

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $validated['role'],
            ],
        ], 201);
    }

    public function settings(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $role = $request->user()->businesses()->whereKey($business->getKey())->value('business_user.role');

        return response()->json(['data' => [
            ...$business->only(['id', 'name', 'currency', 'timezone']),
            'role' => $role,
            'settings' => $business->posSettings(),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $this->authorizeStaffManagement($request, $business);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'currency' => ['required', 'string', Rule::in(['KES', 'USD', 'UGX', 'TZS', 'RWF', 'EUR', 'GBP', 'ZAR'])],
            'timezone' => ['required', 'timezone:all'],
            'settings' => ['required', 'array'],
            'settings.business_phone' => ['nullable', 'string', 'max:40'],
            'settings.business_email' => ['nullable', 'email', 'max:255'],
            'settings.business_address' => ['nullable', 'string', 'max:255'],
            'settings.tax_number' => ['nullable', 'string', 'max:80'],
            'settings.tax_enabled' => ['required', 'boolean'],
            'settings.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:settings.tax_enabled,true'],
            'settings.tax_inclusive' => ['required', 'boolean'],
            'settings.discount_enabled' => ['required', 'boolean'],
            'settings.max_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'settings.payment_methods' => ['required', 'array', 'min:1'],
            'settings.payment_methods.*' => ['required', 'distinct', Rule::in(['cash', 'mpesa', 'card', 'bank', 'credit'])],
            'settings.default_payment_method' => ['required', Rule::in(['cash', 'mpesa', 'card', 'bank', 'credit'])],
            'settings.receipt_show_business_details' => ['required', 'boolean'],
            'settings.receipt_footer' => ['nullable', 'string', 'max:250'],
        ]);

        if (! in_array($validated['settings']['default_payment_method'], $validated['settings']['payment_methods'], true)) {
            throw ValidationException::withMessages([
                'settings.default_payment_method' => 'The default payment method must be enabled.',
            ]);
        }

        foreach (['business_phone', 'business_email', 'business_address', 'tax_number', 'receipt_footer'] as $field) {
            $validated['settings'][$field] ??= '';
        }
        $validated['settings']['tax_rate'] ??= '0.00';
        if (! $validated['settings']['discount_enabled']) {
            $validated['settings']['max_discount_percent'] = '0.00';
        }

        $business->update([
            'name' => $validated['name'],
            'currency' => $validated['currency'],
            'timezone' => $validated['timezone'],
            'settings' => $validated['settings'],
        ]);

        return response()->json(['data' => [
            ...$business->fresh()->only(['id', 'name', 'currency', 'timezone']),
            'role' => $request->user()->businesses()->whereKey($business->getKey())->value('business_user.role'),
            'settings' => $business->posSettings(),
        ]]);
    }

    private function authorizeStaffManagement(Request $request, Business $business): void
    {
        $role = $request->user()->businesses()->whereKey($business->getKey())->value('business_user.role');

        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only business owners and admins can manage staff.');
    }
}
