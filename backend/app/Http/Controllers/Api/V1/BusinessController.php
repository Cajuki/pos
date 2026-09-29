<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
