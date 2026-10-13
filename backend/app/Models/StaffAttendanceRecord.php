<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'staff_profile_id', 'branch_id', 'corrected_by', 'clocked_in_at', 'clocked_out_at', 'correction_note'])]
class StaffAttendanceRecord extends Model
{
    protected function casts(): array
    {
        return ['clocked_in_at' => 'datetime', 'clocked_out_at' => 'datetime'];
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }
}
