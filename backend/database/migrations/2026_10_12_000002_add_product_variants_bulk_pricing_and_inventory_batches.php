<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('variant_attributes')->nullable();
            $table->json('bulk_pricing')->nullable();
            $table->boolean('batch_tracking_enabled')->default(false);
        });

        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('lot_number', 100);
            $table->date('expires_on');
            $table->unsignedInteger('quantity_received');
            $table->unsignedInteger('quantity_remaining');
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'product_id', 'lot_number']);
            $table->index(['business_id', 'product_id', 'expires_on']);
        });

        Schema::create('sale_item_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['sale_item_id', 'inventory_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_batches');
        Schema::dropIfExists('inventory_batches');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['variant_attributes', 'bulk_pricing', 'batch_tracking_enabled']);
        });
    }
};
