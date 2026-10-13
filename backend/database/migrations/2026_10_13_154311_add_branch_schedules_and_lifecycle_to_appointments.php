<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedSmallInteger('service_buffer_minutes')->default(0);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->string('booking_source', 16)->default('appointment');
            $table->timestamp('checked_in_at')->nullable();
            $table->index(['business_id', 'branch_id', 'starts_at', 'status']);
            $table->foreign(['business_id', 'branch_id'])
                ->references(['business_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
        });

        DB::table('appointments')->whereNull('branch_id')->orderBy('id')->chunkById(250, function ($appointments): void {
            foreach ($appointments as $appointment) {
                $branchId = DB::table('branches')
                    ->where('business_id', $appointment->business_id)
                    ->where('is_default', true)
                    ->value('id');
                if ($branchId) {
                    DB::table('appointments')->where('id', $appointment->id)->update(['branch_id' => $branchId]);
                }
            }
        });

        Schema::create('staff_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('staff_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->time('break_starts_at')->nullable();
            $table->time('break_ends_at')->nullable();
            $table->boolean('is_working')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'branch_id', 'staff_user_id', 'day_of_week']);
            $table->index(['business_id', 'staff_user_id', 'day_of_week', 'is_working']);
            $table->foreign(['business_id', 'branch_id'])
                ->references(['business_id', 'id'])
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_schedules');
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['business_id', 'branch_id']);
            $table->dropIndex(['business_id', 'branch_id', 'starts_at', 'status']);
            $table->dropColumn(['branch_id', 'buffer_minutes', 'booking_source', 'checked_in_at']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('service_buffer_minutes');
        });
    }
};
