<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read int $id
 */
#[Fillable(['business_id', 'sku', 'barcode', 'barcode_tracking_enabled', 'name', 'category', 'description', 'unit_price', 'cost_price', 'reorder_level', 'is_active'])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'reorder_level' => 'integer',
            'is_active' => 'boolean',
            'barcode_tracking_enabled' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function inventoryStock(): HasOne
    {
        return $this->hasOne(InventoryStock::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function trackedBarcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }
}
