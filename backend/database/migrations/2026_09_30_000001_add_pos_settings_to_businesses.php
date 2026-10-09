<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->json('settings')->default('{}');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'tax_rate']);
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }
};
