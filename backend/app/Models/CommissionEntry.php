<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'branch_id', 'sale_id', 'sale_item_id', 'staff_user_id', 'staff_profile_id', 'basis_amount', 'earned_amount', 'reversed_amount', 'status', 'approved_by', 'approved_at', 'paid_at', 'payout_reference'])]
class CommissionEntry extends Model
{
    protected function casts(): array
    {
        return [
            'basis_amount' => 'decimal:2',
            'earned_amount' => 'decimal:2',
            'reversed_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CommissionAdjustment::class);
    }
}
