<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_vulnerability_profiles', function (Blueprint $table) {
            $table->unsignedInteger('region_id')->primary();
            $table->decimal('pct_children', 5, 2)->nullable();
            $table->decimal('pct_elderly', 5, 2)->nullable();
            $table->decimal('pct_outdoor_labor', 5, 2)->nullable();
            $table->unsignedInteger('sensitive_site_count')->nullable();
            $table->string('source', 100)->nullable();
            $table->timestamps();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
        });

        Schema::create('region_scoring_configs', function (Blueprint $table) {
            $table->increments('region_scoring_config_id');
            $table->unsignedSmallInteger('index_id');
            $table->unsignedInteger('region_id')->nullable();
            $table->unsignedSmallInteger('signal_type_id');
            $table->decimal('weight', 5, 4);
            $table->decimal('vulnerability_weight', 5, 4)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
            $table->foreign('signal_type_id')->references('signal_type_id')->on('signal_types');
            $table->unique(['index_id', 'region_id', 'signal_type_id'], 'region_scoring_configs_unique_signal');
        });

        Schema::create('scoring_calibration_parameters', function (Blueprint $table) {
            $table->increments('scoring_calibration_parameter_id');
            $table->unsignedSmallInteger('index_id');
            $table->unsignedInteger('region_id')->nullable();
            $table->string('parameter_key', 100);
            $table->decimal('parameter_value', 12, 6)->nullable();
            $table->json('parameter_metadata')->nullable();
            $table->text('source_reference')->nullable();
            $table->timestamps();
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
            $table->unique(['index_id', 'region_id', 'parameter_key'], 'scoring_calibration_parameters_unique_key');
        });

        Schema::create('region_scores', function (Blueprint $table) {
            $table->unsignedSmallInteger('index_id');
            $table->unsignedInteger('region_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('score', 5, 2)->check('score >= 0 AND score <= 100');
            $table->string('scoring_strategy', 30)->default('formula');
            $table->string('scoring_version', 30)->nullable();
            $table->json('breakdown')->nullable();
            $table->text('ai_summary')->nullable();
            $table->timestamp('ai_summary_generated_at')->nullable();
            $table->string('ai_summary_model', 60)->nullable();
            $table->timestamp('calculated_at')->useCurrent();
            $table->primary(['index_id', 'region_id', 'period_start']);
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
            $table->index(['region_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_scores');
        Schema::dropIfExists('scoring_calibration_parameters');
        Schema::dropIfExists('region_scoring_configs');
        Schema::dropIfExists('region_vulnerability_profiles');
    }
};
