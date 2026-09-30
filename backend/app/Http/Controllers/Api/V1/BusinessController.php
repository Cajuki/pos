<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    private function authorizeStaffManagement(Request $request, Business $business): void
    {
        $role = $request->user()->businesses()->whereKey($business->getKey())->value('business_user.role');

        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only business owners and admins can manage staff.');
    }
}
