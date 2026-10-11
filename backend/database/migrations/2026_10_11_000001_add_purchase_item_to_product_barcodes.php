<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table): void {
            $table->foreignId('purchase_item_id')
                ->nullable()
                ->after('sale_item_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_item_id');
        });
    }
};
