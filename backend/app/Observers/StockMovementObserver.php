<?php

namespace App\Observers;

use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class StockMovementObserver
{
    public function created(StockMovement $movement): void
    {
        DB::table('audit_logs')->insert([
            'business_id' => $movement->business_id,
            'user_id' => $movement->created_by,
            'action' => 'inventory.stock_movement_recorded',
            'entity_type' => StockMovement::class,
            'entity_id' => (string) $movement->id,
            'new_values' => json_encode([
                'product_id' => $movement->product_id,
                'sale_id' => $movement->sale_id,
                'type' => $movement->type,
                'quantity_change' => $movement->quantity_change,
                'quantity_before' => $movement->quantity_before,
                'quantity_after' => $movement->quantity_after,
                'reason' => $movement->reason,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $movement->created_at ?? now(),
        ]);
    }
}
