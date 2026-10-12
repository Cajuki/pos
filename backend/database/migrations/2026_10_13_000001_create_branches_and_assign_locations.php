<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 40);
            $table->text('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'code']);
            $table->unique(['business_id', 'id']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->index(['business_id', 'branch_id']);
            $table->foreign(['business_id', 'branch_id'])
                ->references(['business_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
        });

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->index(['business_id', 'branch_id']);
            $table->foreign(['business_id', 'branch_id'])
                ->references(['business_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
        });

        DB::table('businesses')->orderBy('id')->chunk(100, function ($businesses): void {
            foreach ($businesses as $business) {
                $now = now();
                $branchId = DB::table('branches')->insertGetId([
                    'business_id' => $business->id,
                    'name' => 'Main Branch',
                    'code' => 'MAIN',
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $warehouses = DB::table('warehouses')->where('business_id', $business->id)->orderBy('id')->get();
                if ($warehouses->isEmpty()) {
                    DB::table('warehouses')->insert([
                        'business_id' => $business->id,
                        'name' => 'Main Warehouse',
                        'code' => 'MAIN',
                        'address' => null,
                        'is_default' => true,
                        'is_active' => true,
                        'branch_id' => $branchId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('warehouses')->where('business_id', $business->id)->update(['branch_id' => $branchId]);
                    if (! $warehouses->contains(fn ($warehouse): bool => (bool) $warehouse->is_default)) {
                        DB::table('warehouses')->where('id', $warehouses->first()->id)->update(['is_default' => true]);
                    }
                }

                DB::table('cash_registers')->where('business_id', $business->id)->update(['branch_id' => $branchId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->dropForeign(['business_id', 'branch_id']);
            $table->dropIndex(['business_id', 'branch_id']);
            $table->dropColumn('branch_id');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropForeign(['business_id', 'branch_id']);
            $table->dropIndex(['business_id', 'branch_id']);
            $table->dropColumn('branch_id');
        });

        Schema::dropIfExists('branches');
    }
};
