<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_job_titles', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->json('default_permissions')->nullable();
            $table->boolean('is_predefined')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_enabled']);
        });

        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_id', 64)->nullable();
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('employment_type', 32)->default('full_time');
            $table->date('employment_started_on')->nullable();
            $table->string('employment_status', 24)->default('active');
            $table->json('permission_overrides')->nullable();
            $table->string('commission_type', 16)->nullable();
            $table->decimal('commission_value', 12, 2)->nullable();
            $table->boolean('product_commission_enabled')->default(false);
            $table->decimal('product_commission_value', 12, 2)->nullable();
            $table->decimal('salary_amount', 12, 2)->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'employee_id']);
            $table->unique(['business_id', 'user_id']);
            $table->index(['business_id', 'employment_status', 'name']);
        });

        Schema::create('staff_profile_job_titles', function (Blueprint $table): void {
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_job_title_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->primary(['staff_profile_id', 'staff_job_title_id']);
        });

        Schema::create('staff_profile_branches', function (Blueprint $table): void {
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->primary(['staff_profile_id', 'branch_id']);
            $table->foreign(['business_id', 'branch_id'])->references(['business_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->index(['business_id', 'branch_id']);
        });

        Schema::create('staff_service_assignments', function (Blueprint $table): void {
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('commission_eligible')->default(true);
            $table->primary(['staff_profile_id', 'product_id']);
            $table->index(['business_id', 'product_id']);
        });

        Schema::create('staff_profile_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->time('break_starts_at')->nullable();
            $table->time('break_ends_at')->nullable();
            $table->boolean('is_working')->default(true);
            $table->timestamps();
            $table->unique(['staff_profile_id', 'branch_id', 'day_of_week']);
            $table->foreign(['business_id', 'branch_id'])->references(['business_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->index(['business_id', 'staff_profile_id', 'day_of_week']);
        });

        Schema::create('staff_leave_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 16)->default('approved');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->index(['business_id', 'staff_profile_id', 'starts_at', 'ends_at']);
        });

        Schema::create('staff_attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            $table->string('correction_note', 500)->nullable();
            $table->timestamps();
            $table->index(['business_id', 'staff_profile_id', 'clocked_in_at']);
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('staff_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['business_id', 'staff_profile_id', 'starts_at', 'status']);
        });

        Schema::table('commission_entries', function (Blueprint $table): void {
            $table->foreignId('staff_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['business_id', 'staff_profile_id', 'status', 'created_at'], 'commission_staff_profile_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commission_entries', function (Blueprint $table): void {
            $table->dropIndex('commission_staff_profile_status_created_idx');
            $table->dropConstrainedForeignId('staff_profile_id');
        });
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'staff_profile_id', 'starts_at', 'status']);
            $table->dropConstrainedForeignId('staff_profile_id');
        });
        Schema::dropIfExists('staff_attendance_records');
        Schema::dropIfExists('staff_leave_records');
        Schema::dropIfExists('staff_profile_schedules');
        Schema::dropIfExists('staff_service_assignments');
        Schema::dropIfExists('staff_profile_branches');
        Schema::dropIfExists('staff_profile_job_titles');
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('staff_job_titles');
    }
};
