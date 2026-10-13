<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'staff_profile_id', 'branch_id', 'day_of_week', 'starts_at', 'ends_at', 'break_starts_at', 'break_ends_at', 'is_working'])]
class StaffProfileSchedule extends Model
{
    protected function casts(): array
    {
        return ['is_working' => 'boolean', 'day_of_week' => 'integer'];
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }
}
