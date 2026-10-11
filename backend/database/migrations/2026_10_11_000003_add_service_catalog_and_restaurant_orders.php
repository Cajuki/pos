<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('product_type', 16)->default('goods');
            $table->unsignedSmallInteger('service_duration_minutes')->nullable();
        });

        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->unsignedInteger('quantity_reserved')->default(0);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->string('order_type', 16)->nullable();
            $table->string('table_name', 80)->nullable();
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('restaurant_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_type', 16)->default('dine_in');
            $table->string('table_name', 80)->nullable();
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status', 'created_at']);
        });

        Schema::create('restaurant_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_order_id', 'product_id']);
        });

        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('status', 20)->default('booked');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'starts_at', 'status']);
            $table->index(['staff_user_id', 'starts_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('restaurant_order_items');
        Schema::dropIfExists('restaurant_orders');

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('performed_by_user_id');
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['order_type', 'table_name']);
        });
        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->dropColumn('quantity_reserved');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['product_type', 'service_duration_minutes']);
        });
    }
};
