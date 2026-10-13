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
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand_name')->nullable();
            $table->string('generic_name')->nullable();
            $table->string('active_ingredient')->nullable();
            $table->string('strength')->nullable();
            $table->string('dosage_form')->nullable();
            $table->string('pack_size')->nullable();
            $table->string('unit_of_sale')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('medicine_classification')->nullable();
            $table->boolean('prescription_required')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'brand_name', 'generic_name', 'active_ingredient', 'strength', 'dosage_form',
                'pack_size', 'unit_of_sale', 'manufacturer', 'registration_number',
                'medicine_classification', 'prescription_required',
            ]);
        });
    }
};
