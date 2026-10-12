<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('cash_register_id')->nullable()->after('user_id')->constrained('cash_registers')->restrictOnDelete();
            $table->index(['business_id', 'cash_register_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'cash_register_id', 'created_at']);
            $table->dropConstrainedForeignId('cash_register_id');
        });
    }
};
