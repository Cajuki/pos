<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function registerBusiness(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
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

        [$user, $business] = DB::transaction(function () use ($validated): array {
            $business = Business::create([
                'name' => $validated['business_name'],
                'currency' => 'KES',
                'timezone' => 'Africa/Nairobi',
            ]);
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);
            $user->businesses()->attach($business, ['role' => 'owner']);

            return [$user, $business];
        });

        return response()->json([
            'data' => [
                'token' => $user->createToken('pos-web')->plainTextToken,
                'user' => $user,
                'business' => $business->only(['id', 'name', 'currency', 'timezone']),
                'role' => 'owner',
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
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

        return response()->json([
            'data' => [
                'token' => $user->createToken($validated['device_name'] ?? 'pos-web')->plainTextToken,
                'user' => $user,
                'businesses' => $user->businesses()->get()->map(fn (Business $business): array => [
                    ...$business->only(['id', 'name', 'currency', 'timezone']),
                    'role' => $business->pivot->role,
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

    public function currentBusiness(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $membership = $request->user()->businesses()->whereKey($business->getKey())->firstOrFail();

        return response()->json([
            'data' => [
                ...$business->only(['id', 'name', 'currency', 'timezone']),
                'role' => $membership->pivot->role,
            ],
        ]);
    }
}
