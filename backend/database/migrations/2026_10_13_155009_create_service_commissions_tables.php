<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('commission_type', 16)->nullable();
            $table->decimal('commission_value', 12, 2)->nullable();
        });

        Schema::create('commission_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('basis_amount', 12, 2);
            $table->decimal('earned_amount', 12, 2);
            $table->decimal('reversed_amount', 12, 2)->default(0);
            $table->string('status', 16)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_reference', 120)->nullable();
            $table->timestamps();
            $table->index(['business_id', 'staff_user_id', 'status', 'created_at']);
            $table->foreign(['business_id', 'branch_id'])
                ->references(['business_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
        });

        Schema::create('commission_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->constrained()->restrictOnDelete();
            $table->foreignId('refund_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->timestamps();
            $table->index(['business_id', 'commission_entry_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_adjustments');
        Schema::dropIfExists('commission_entries');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['commission_type', 'commission_value']);
        });
    }
};
