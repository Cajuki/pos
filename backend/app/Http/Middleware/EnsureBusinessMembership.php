<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $businessId = $request->header('X-Business-ID');

        if (! $businessId) {
            return response()->json(['message' => 'A business context is required.'], 400);
        }

        $business = Business::query()
            ->whereKey($businessId)
            ->whereHas('users', fn ($query) => $query->whereKey($request->user()->getKey()))
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found.'], 404);
        }

        $request->attributes->set('business', $business);

        return $next($request);
    }
}
