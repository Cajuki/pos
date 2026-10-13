<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'branch_id', 'customer_id', 'service_product_id', 'staff_user_id', 'staff_profile_id', 'sale_id', 'starts_at', 'duration_minutes', 'buffer_minutes', 'booking_source', 'checked_in_at', 'status', 'notes'])]
class Appointment extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'checked_in_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'service_product_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
