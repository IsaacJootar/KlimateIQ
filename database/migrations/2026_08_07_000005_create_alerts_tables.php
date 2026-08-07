<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threshold_configs', function (Blueprint $table) {
            $table->uuid('threshold_config_id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('agency_id')->nullable();
            $table->unsignedInteger('region_id')->nullable();
            $table->unsignedSmallInteger('index_id')->nullable();
            $table->unsignedSmallInteger('signal_type_id')->nullable();
            $table->string('alert_type', 20)->default('fixed_threshold');
            $table->string('comparison_operator', 2)->nullable();
            $table->decimal('threshold_value', 12, 4)->nullable();
            $table->decimal('anomaly_stddev_multiplier', 5, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->cascadeOnDelete();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
            $table->foreign('signal_type_id')->references('signal_type_id')->on('signal_types')->cascadeOnDelete();
        });

        // Exactly one of index_id / signal_type_id identifies what the threshold watches.
        DB::statement(
            'ALTER TABLE threshold_configs ADD CONSTRAINT threshold_configs_exactly_one_target '.
            'CHECK ((index_id IS NOT NULL)::int + (signal_type_id IS NOT NULL)::int = 1)'
        );

        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('alert_id')->primary();
            $table->uuid('threshold_config_id');
            $table->unsignedInteger('region_id');
            $table->unsignedSmallInteger('index_id')->nullable();
            $table->unsignedSmallInteger('signal_type_id')->nullable();
            $table->decimal('score_at_trigger', 12, 4);
            $table->decimal('threshold_value', 12, 4)->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->timestamp('triggered_at')->useCurrent();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->foreign('threshold_config_id')->references('threshold_config_id')->on('threshold_configs')->cascadeOnDelete();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
            $table->foreign('signal_type_id')->references('signal_type_id')->on('signal_types')->cascadeOnDelete();
            $table->index(['region_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('threshold_configs');
    }
};
