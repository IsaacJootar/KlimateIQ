<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD_PLAN.md T4 — the forecast signal lane. Deliberately a separate table from
 * `region_signals`, not a flag on it: forecast and observed data must never mix in a query
 * (an anomaly baseline or a backtest built on a forecast value silently treated as an
 * observation is the failure mode the whole "separate lanes" discipline guards against).
 *
 * Mirrors the `region_signals` column contract, plus forecast provenance: which day the
 * forecast was issued, which day it is about, and the lead time between them. v1 keeps only
 * the latest issuance per (region, signal, target date) — issuance history for forecast-skill
 * backtesting is T5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_forecast_signals', function (Blueprint $table) {
            $table->bigIncrements('region_forecast_signal_id');
            $table->unsignedInteger('region_id');
            $table->unsignedSmallInteger('signal_type_id');
            $table->date('forecast_issued_at');
            $table->date('target_date');
            $table->unsignedSmallInteger('lead_days');
            $table->decimal('value', 12, 4);
            $table->json('raw_metadata')->nullable();
            $table->string('source', 100);
            $table->timestamp('ingested_at')->useCurrent();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
            $table->foreign('signal_type_id')->references('signal_type_id')->on('signal_types');
            $table->unique(['region_id', 'signal_type_id', 'target_date'], 'region_forecast_signals_unique_target');
            $table->index(['region_id', 'target_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_forecast_signals');
    }
};
