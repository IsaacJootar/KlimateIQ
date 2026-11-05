<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise pivot, Module 11 (docs/ENTERPRISE_BUILD_PLAN.md) — "what to do" recommendations can
 * now be scoped to a Portfolio Type as well as an index/risk-band pair. A row with
 * portfolio_type = null is the generic fallback every existing recommendation already is;
 * App\Models\IndexActionRecommendation::textFor() prefers a type-specific match when one exists,
 * so a region reached from an Insurance portfolio reads differently than one reached from the
 * public Regions browse page, without losing the generic text for everyone else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('index_action_recommendations', function (Blueprint $table) {
            $table->dropUnique(['index_id', 'risk_band']);
            $table->string('portfolio_type', 40)->nullable()->after('risk_band');
            $table->unique(['index_id', 'risk_band', 'portfolio_type']);
        });
    }

    public function down(): void
    {
        Schema::table('index_action_recommendations', function (Blueprint $table) {
            $table->dropUnique(['index_id', 'risk_band', 'portfolio_type']);
            $table->dropColumn('portfolio_type');
            $table->unique(['index_id', 'risk_band']);
        });
    }
};
