<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['sale_id', 'product_id', 'sku', 'barcode', 'product_name', 'quantity', 'unit_price', 'unit_cost', 'line_total', 'performed_by_user_id'])]
class SaleItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productBarcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class);
    }

    public function refundItems(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function commissionEntry(): HasOne
    {
        return $this->hasOne(CommissionEntry::class);
    }
}
