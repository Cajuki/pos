<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('barcode_tracking_enabled')->default(false);
        });

        Schema::create('product_barcodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('barcode', 100);
            $table->string('status', 16)->default('in_stock');
            $table->timestamps();
            $table->unique(['business_id', 'barcode']);
            $table->index(['business_id', 'product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('barcode_tracking_enabled');
        });
    }
};
