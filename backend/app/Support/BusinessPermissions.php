<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;

class BusinessPermissions
{
    public function forBusiness(User $user, Business $business): array
    {
        if ($user->is_platform_admin) {
            return ['*'];
        }

        $role = $user->businesses()
            ->whereKey($business->getKey())
            ->value('business_user.role');
        if ($role && ! in_array($role, ['owner', 'admin', 'platform_admin'], true)) {
            $staffProfile = $business->staffProfiles()
                ->where('user_id', $user->id)
                ->first();
            $staffPermissions = $staffProfile?->permission_overrides;
            if (is_array($staffPermissions)) {
                return $staffPermissions;
            }
        }
        $permissions = $role
            ? ($business->posSettings()['role_permissions'][$role] ?? config('pos.roles.'.$role))
            : null;

        return $permissions === '*' ? ['*'] : (is_array($permissions) ? $permissions : []);
    }

    public function allows(User $user, Business $business, string $permission): bool
    {
        $permissions = $this->forBusiness($user, $business);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
