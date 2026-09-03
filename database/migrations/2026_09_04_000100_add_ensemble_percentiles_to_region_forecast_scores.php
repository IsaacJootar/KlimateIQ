<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD_PLAN.md T5 — probabilistic scoring. Additive, all nullable: the deterministic `score` /
 * `peak_date` / `lead_days_to_peak` stay the control values (every existing reader is
 * unaffected); these carry the ensemble distribution alongside.
 *
 *   p10 / p50 / p90            — percentiles of the per-member peak score
 *   exceedance_probability     — share of members whose peak reaches exceedance_reference
 *   exceedance_reference       — the level that probability is about (default 67, the red cutoff)
 *   member_count               — how many members resolved
 *
 * The sorted per-member peak array and a per-day p10/p50/p90 series live in `breakdown`
 * (`members`, `member_daily`), so P(index ≥ any threshold) can be read off the empirical
 * distribution at alert time without a per-member table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('region_forecast_scores', function (Blueprint $table) {
            $table->decimal('p10', 5, 2)->nullable()->after('score');
            $table->decimal('p50', 5, 2)->nullable()->after('p10');
            $table->decimal('p90', 5, 2)->nullable()->after('p50');
            $table->decimal('exceedance_probability', 5, 4)->nullable()->after('p90');
            $table->decimal('exceedance_reference', 5, 2)->nullable()->default(67)->after('exceedance_probability');
            $table->unsignedSmallInteger('member_count')->nullable()->after('exceedance_reference');
        });
    }

    public function down(): void
    {
        Schema::table('region_forecast_scores', function (Blueprint $table) {
            $table->dropColumn(['p10', 'p50', 'p90', 'exceedance_probability', 'exceedance_reference', 'member_count']);
        });
    }
};
