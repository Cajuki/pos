<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\BusinessPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function registerBusiness(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'industry' => ['sometimes', 'string', Rule::in(array_keys(config('pos.industries')))],
            'currency' => ['sometimes', 'string', Rule::in(['KES', 'USD', 'UGX', 'TZS', 'RWF', 'EUR', 'GBP', 'ZAR'])],
            'timezone' => ['sometimes', 'timezone:all'],
            'business_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'business_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'business_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'min:12',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
                'confirmed',
            ],
        ]);

        [$user, $business, $token] = DB::transaction(function () use ($validated): array {
            $industry = $validated['industry'] ?? 'general';
            $business = Business::create([
                'name' => $validated['business_name'],
                'industry' => $industry,
                'currency' => $validated['currency'] ?? 'KES',
                'timezone' => $validated['timezone'] ?? 'Africa/Nairobi',
                'settings' => [
                    'business_phone' => $validated['business_phone'] ?? '',
                    'business_email' => $validated['business_email'] ?? '',
                    'business_address' => $validated['business_address'] ?? '',
                ],
            ]);
            $branch = Branch::create([
                'business_id' => $business->id,
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_default' => true,
                'is_active' => true,
            ]);
            Warehouse::create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'name' => 'Main Warehouse',
                'code' => 'MAIN',
                'is_default' => true,
                'is_active' => true,
            ]);
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);
            $user->businesses()->attach($business, ['role' => 'owner']);
            $plan = SubscriptionPlan::query()->where('code', 'starter')->where('is_active', true)->firstOrFail();
            $business->subscriptions()->create([
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
            ]);
            $token = $user->createToken('pos-web')->plainTextToken;

            return [$user, $business, $token];
        });

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $user,
                'business' => [
                    ...$business->only(['id', 'name', 'currency', 'timezone', 'industry']),
                    'modules' => $business->industryModules(),
                ],
                'role' => 'owner',
            ],
        ], 201);
    }

    public function login(Request $request, BusinessPermissions $permissions): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $businesses = $user->is_platform_admin
            ? Business::query()->orderBy('name')->get()
            : $user->businesses()->get();

        return response()->json([
            'data' => [
                'token' => $user->createToken($validated['device_name'] ?? 'pos-web')->plainTextToken,
                'user' => $user,
                'businesses' => $businesses->map(fn (Business $business): array => [
                    ...$business->only(['id', 'name', 'currency', 'timezone', 'industry']),
                    'role' => $user->is_platform_admin ? 'platform_admin' : $business->pivot->role,
                    'modules' => $business->industryModules(),
                    'permissions' => $permissions->forBusiness($user, $business),
                ]),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token && method_exists($token, 'getKey')) {
            $request->user()->tokens()->whereKey($token->getKey())->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function currentBusiness(Request $request, BusinessPermissions $permissions): JsonResponse
    {
        $business = $request->attributes->get('business');
        $membership = $request->user()->businesses()->whereKey($business->getKey())->first();
        abort_unless($membership || $request->user()->is_platform_admin, 404);

        return response()->json([
            'data' => [
                ...$business->only(['id', 'name', 'currency', 'timezone', 'industry']),
                'role' => $membership?->pivot->role ?? 'platform_admin',
                'industry' => $business->industry,
                'modules' => $business->industryModules(),
                'permissions' => $permissions->forBusiness($request->user(), $business),
            ],
        ]);
    }
}
