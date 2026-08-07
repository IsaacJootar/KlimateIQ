<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Population-exposure bounds run into the millions; decimal(12,6) only fits 6 integer
        // digits.
        Schema::table('scoring_calibration_parameters', function (Blueprint $table) {
            $table->decimal('parameter_value', 18, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('scoring_calibration_parameters', function (Blueprint $table) {
            $table->decimal('parameter_value', 12, 6)->nullable()->change();
        });
    }
};
