<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'commission_entry_id', 'refund_id', 'refund_item_id', 'created_by', 'amount', 'reason'])]
class CommissionAdjustment extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CommissionEntry::class, 'commission_entry_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
