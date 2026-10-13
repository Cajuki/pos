<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 80);
            $table->string('area', 80)->nullable();
            $table->unsignedSmallInteger('seating_capacity')->default(2);
            $table->string('status', 20)->default('available');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'branch_id', 'name']);
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'branch_id', 'status', 'is_active']);
            $table->foreign(['business_id', 'branch_id'])
                ->references(['business_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
        });

        Schema::table('restaurant_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('restaurant_table_id')->nullable();
            $table->index(['business_id', 'restaurant_table_id', 'status']);
            $table->foreign(['business_id', 'restaurant_table_id'])
                ->references(['business_id', 'id'])
                ->on('restaurant_tables')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table): void {
            $table->dropForeign(['business_id', 'restaurant_table_id']);
            $table->dropIndex(['business_id', 'restaurant_table_id', 'status']);
            $table->dropColumn('restaurant_table_id');
        });

        Schema::dropIfExists('restaurant_tables');
    }
};
