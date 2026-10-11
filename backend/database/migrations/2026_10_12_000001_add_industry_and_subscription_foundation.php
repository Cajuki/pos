<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('industry', 40)->default('general')->index();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false);
        });

        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->json('features');
            $table->json('limits');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('business_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status', 'starts_at']);
        });

        $now = now();
        $planId = DB::table('subscription_plans')->insertGetId([
            'code' => 'starter',
            'name' => 'Starter',
            'features' => json_encode(config('pos.plans.starter.features'), JSON_THROW_ON_ERROR),
            'limits' => json_encode(config('pos.plans.starter.limits'), JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('businesses')->orderBy('id')->select('id')->chunk(100, function ($businesses) use ($planId, $now): void {
            DB::table('business_subscriptions')->insert(
                $businesses->map(fn (object $business): array => [
                    'business_id' => $business->id,
                    'subscription_plan_id' => $planId,
                    'status' => 'active',
                    'starts_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_subscriptions');
        Schema::dropIfExists('subscription_plans');

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropIndex(['industry']);
            $table->dropColumn('industry');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }
};
