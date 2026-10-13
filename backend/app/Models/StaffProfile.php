<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'user_id', 'employee_id', 'name', 'phone', 'email', 'employment_type', 'employment_started_on', 'employment_status', 'permission_overrides', 'commission_type', 'commission_value', 'product_commission_enabled', 'product_commission_value', 'salary_amount', 'salary_currency'])]
class StaffProfile extends Model
{
    protected function casts(): array
    {
        return [
            'employment_started_on' => 'date',
            'permission_overrides' => 'array',
            'commission_value' => 'decimal:2',
            'product_commission_enabled' => 'boolean',
            'product_commission_value' => 'decimal:2',
            'salary_amount' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobTitles(): BelongsToMany
    {
        return $this->belongsToMany(StaffJobTitle::class, 'staff_profile_job_titles')->withPivot('is_primary');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'staff_profile_branches')->withPivot('business_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'staff_service_assignments')->withPivot(['business_id', 'commission_eligible']);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffProfileSchedule::class);
    }

    public function leaveRecords(): HasMany
    {
        return $this->hasMany(StaffLeaveRecord::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(StaffAttendanceRecord::class);
    }
}
