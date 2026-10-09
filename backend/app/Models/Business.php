<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property-read string $id
 * @property-read Pivot $pivot
 */
#[Fillable(['name', 'currency', 'timezone', 'status', 'settings'])]
class Business extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function posSettings(): array
    {
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
        ], $this->settings ?? []);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
}
