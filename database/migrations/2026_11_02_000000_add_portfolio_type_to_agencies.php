<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise pivot, Module 1 (docs/ENTERPRISE_BUILD_PLAN.md) — a constrained, enum-backed
 * classification of what kind of organisation an agency is, driving which indices it sees by
 * default (App\Support\PortfolioType).
 *
 * Deliberately a NEW column alongside the existing free-text `type`, not a rename/constraint of
 * it: `type` already holds real, varied legacy labels ("State Ministry of Health", "NGO /
 * Development Partner", "Research Institution") that don't map cleanly onto the new enum, and
 * coercing them would be a lossy, one-way data change for no real benefit — `type` keeps
 * describing what an agency *is*, `portfolio_type` drives what it *sees*. Nullable: existing
 * agencies have no portfolio type until deliberately set (resolves to "show everything" — see
 * App\Support\AgencyIndexCoverage).
 *
 * `agency_index_overrides` is the per-agency admin override on top of the type default: a row
 * for a given index forces it in (enabled=true) or out (enabled=false) regardless of what the
 * portfolio type would otherwise show. No row for an index means the type default applies
 * untouched — same "only store what's different" shape as every other override table in this
 * codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('portfolio_type', 40)->nullable()->after('type');
        });

        Schema::create('agency_index_overrides', function (Blueprint $table) {
            $table->increments('agency_index_override_id');
            $table->uuid('agency_id');
            $table->unsignedSmallInteger('index_id');
            $table->boolean('enabled');
            $table->timestamps();
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->cascadeOnDelete();
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
            $table->unique(['agency_id', 'index_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_index_overrides');

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('portfolio_type');
        });
    }
};
