<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'branch_id', 'name', 'area', 'seating_capacity', 'status', 'is_active'])]
class RestaurantTable extends Model
{
    protected function casts(): array
    {
        return ['seating_capacity' => 'integer', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(RestaurantOrder::class, 'restaurant_table_id');
    }
}
