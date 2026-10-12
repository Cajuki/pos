<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property-read string $id
 * @property-read Pivot $pivot
 */
#[Fillable(['name', 'currency', 'timezone', 'status', 'settings', 'industry'])]
class Business extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function posSettings(): array
    {
        $industryModules = config('pos.industries.'.$this->industry.'.modules', []);

        return array_replace([
            'business_phone' => '',
            'business_email' => '',
            'business_address' => '',
            'tax_number' => '',
            'tax_enabled' => false,
            'tax_rate' => '0.00',
            'tax_inclusive' => false,
            'discount_enabled' => false,
            'max_discount_percent' => '0.00',
            'payment_methods' => ['cash', 'mpesa', 'card', 'bank', 'credit'],
            'default_payment_method' => 'cash',
            'receipt_show_business_details' => true,
            'receipt_footer' => 'Thank you for shopping with us.',
            'enabled_modules' => $industryModules,
            'role_permissions' => [],
        ], $this->settings ?? [], [
            'enabled_modules' => $this->settings['enabled_modules'] ?? $industryModules,
        ]);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function teamInvitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BusinessSubscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(BusinessSubscription::class)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latestOfMany();
    }

    public function industryModules(): array
    {
        $industry = config('pos.industries.'.$this->industry);
        $configuredModules = $this->settings['enabled_modules'] ?? $industry['modules'] ?? [];

        return array_values(array_unique([
            ...config('pos.core_modules'),
            ...$configuredModules,
        ]));
    }
}
