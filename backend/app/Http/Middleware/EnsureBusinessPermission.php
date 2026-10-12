<?php

namespace App\Http\Middleware;

use App\Support\BusinessPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessPermission
{
    public function __construct(private readonly BusinessPermissions $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $business = $request->attributes->get('business');
        abort_unless($business, 400, 'A business context is required.');

        $path = $request->route()?->uri() ?? '';
        if ($request->user()->is_platform_admin) {
            return $next($request);
        }
        $module = match (true) {
            str_contains($path, 'appointments') => 'appointments',
            str_contains($path, 'restaurant-orders') => 'restaurant',
            default => null,
        };

        if ($module) {
            abort_unless(in_array($module, $business->industryModules(), true), 404, 'This module is not enabled for this business.');
        }

        $permission = $this->permissionFor($request, $path);
        abort_unless($permission && $this->permissions->allows($request->user(), $business, $permission), 403, 'You do not have permission to perform this action.');

        return $next($request);
    }

    private function permissionFor(Request $request, string $path): ?string
    {
        if (str_contains($path, 'staff')) {
            return 'staff.manage';
        }

        if (str_contains($path, 'providers')) {
            return 'appointments.manage';
        }

        if (str_contains($path, 'settings')) {
            if (in_array($request->method(), ['GET', 'HEAD'], true)) {
                return 'settings.read';
            }

            return 'settings.manage';
        }

        if (str_contains($path, 'refunds')) {
            return 'sales.refund';
        }

        if (str_contains($path, 'cash-registers')) {
            if ($request->isMethod('post') && str_ends_with($path, 'cash-registers')) {
                return 'registers.manage';
            }

            if (in_array($request->method(), ['GET', 'HEAD'], true)) {
                return 'sales.read';
            }

            return 'sales.create';
        }

        if (str_contains($path, 'branches')) {
            return in_array($request->method(), ['GET', 'HEAD'], true) ? 'branches.read' : 'branches.manage';
        }

        if (str_contains($path, 'warehouses')) {
            return in_array($request->method(), ['GET', 'HEAD'], true) ? 'warehouses.read' : 'warehouses.manage';
        }

        if (str_contains($path, 'appointments')) {
            return 'appointments.manage';
        }

        if (str_contains($path, 'restaurant-orders')) {
            return 'restaurant.manage';
        }

        $resource = match (true) {
            str_contains($path, 'products'), str_contains($path, 'categories') => 'products',
            str_contains($path, 'inventory'), str_contains($path, 'barcodes') => 'inventory',
            str_contains($path, 'sales'), str_contains($path, 'cash-registers') => 'sales',
            str_contains($path, 'customers') => 'customers',
            str_contains($path, 'suppliers') => 'suppliers',
            str_contains($path, 'purchases') => 'purchases',
            str_contains($path, 'expenses') => 'expenses',
            str_contains($path, 'reports') => 'reports',
            default => null,
        };

        if (! $resource) {
            return null;
        }

        $action = in_array($request->method(), ['GET', 'HEAD'], true) ? 'read' : 'manage';
        if ($resource === 'sales' && $request->isMethod('post')) {
            $action = 'create';
        }

        return $resource.'.'.$action;
    }
}
