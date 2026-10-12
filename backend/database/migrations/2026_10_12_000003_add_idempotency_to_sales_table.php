<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('idempotency_key', 120)->nullable();
            $table->string('idempotency_hash', 64)->nullable();
            $table->unique(['business_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropUnique(['business_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'idempotency_hash']);
        });
    }
};
