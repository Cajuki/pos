<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('staff_user_id')->nullable()->change();
        });
        Schema::table('commission_entries', function (Blueprint $table): void {
            $table->foreignId('staff_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('appointments')->whereNull('staff_user_id')->exists()
            || DB::table('commission_entries')->whereNull('staff_user_id')->exists()) {
            throw new RuntimeException('Cannot make staff user references required while profile-only salon records exist.');
        }
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('staff_user_id')->nullable(false)->change();
        });
        Schema::table('commission_entries', function (Blueprint $table): void {
            $table->foreignId('staff_user_id')->nullable(false)->change();
        });
    }
};
