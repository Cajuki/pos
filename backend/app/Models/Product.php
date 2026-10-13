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
#[Fillable(['business_id', 'sku', 'barcode', 'barcode_tracking_enabled', 'product_type', 'service_duration_minutes', 'service_buffer_minutes', 'commission_type', 'commission_value', 'variant_attributes', 'bulk_pricing', 'batch_tracking_enabled', 'brand_name', 'generic_name', 'active_ingredient', 'strength', 'dosage_form', 'pack_size', 'unit_of_sale', 'manufacturer', 'registration_number', 'medicine_classification', 'prescription_required', 'name', 'category', 'description', 'unit_price', 'cost_price', 'reorder_level', 'is_active'])]
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
            'service_duration_minutes' => 'integer',
            'service_buffer_minutes' => 'integer',
            'commission_value' => 'decimal:2',
            'variant_attributes' => 'array',
            'bulk_pricing' => 'array',
            'batch_tracking_enabled' => 'boolean',
            'prescription_required' => 'boolean',
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
