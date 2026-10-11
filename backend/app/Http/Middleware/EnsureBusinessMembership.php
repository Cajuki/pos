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
            ->when(! $request->user()->is_platform_admin, fn ($query) => $query
                ->whereHas('users', fn ($users) => $users->whereKey($request->user()->getKey())))
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found.'], 404);
        }

        if (! $request->user()->is_platform_admin) {
            $membership = $request->user()->businesses()->whereKey($business->getKey())->firstOrFail();
            $request->attributes->set('business_role', $membership->pivot->role);
        } else {
            $request->attributes->set('business_role', 'platform_admin');
        }

        $request->attributes->set('business', $business);

        return $next($request);
    }
}
