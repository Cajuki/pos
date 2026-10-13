<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['business_id', 'name', 'slug', 'default_permissions', 'is_predefined', 'is_enabled'])]
class StaffJobTitle extends Model
{
    protected function casts(): array
    {
        return ['default_permissions' => 'array', 'is_predefined' => 'boolean', 'is_enabled' => 'boolean'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function staffProfiles(): BelongsToMany
    {
        return $this->belongsToMany(StaffProfile::class, 'staff_profile_job_titles')->withPivot('is_primary');
    }
}
