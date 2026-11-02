<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise pivot, Module 2 (docs/ENTERPRISE_BUILD_PLAN.md) — Entity Modeling: a named list of
 * locations belonging to an agency, scored and rolled up into one view
 * (App\Services\Portfolio\PortfolioRollupService). This is the build that unlocks Sectors #1, #2
 * and #4 (docs/ENTERPRISE_PIVOT_PLAN.md §5) — a bank's loan-book LGAs, a telecom's site list, and
 * an insurer's covered LGAs are all the same underlying shape.
 *
 * `type` distinguishes a flat point list from a corridor (Module 8 — an ordered route, where
 * `order` on portfolio_locations becomes meaningful) — stored now so Module 8 doesn't need
 * another migration later.
 *
 * A portfolio_location is either a seeded Region (region_id) or a raw coordinate (latitude/
 * longitude + label) — point/coordinate scoring itself is Module 3, not built here. A
 * coordinate-only location is accepted and stored now, but PortfolioRollupService marks it
 * explicitly "not scorable yet" rather than silently guessing — see that service's docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolios', function (Blueprint $table) {
            $table->increments('portfolio_id');
            $table->uuid('agency_id');
            $table->string('name', 200);
            $table->string('type', 20)->default('point_list'); // 'point_list' | 'corridor'
            $table->timestamps();
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->cascadeOnDelete();
        });

        Schema::create('portfolio_locations', function (Blueprint $table) {
            $table->increments('portfolio_location_id');
            $table->unsignedInteger('portfolio_id');
            $table->unsignedInteger('region_id')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('label', 200)->nullable();
            $table->unsignedInteger('order')->nullable();
            $table->timestamps();
            $table->foreign('portfolio_id')->references('portfolio_id')->on('portfolios')->cascadeOnDelete();
            $table->foreign('region_id')->references('region_id')->on('regions')->nullOnDelete();
        });

        // Every location needs somewhere real — either a seeded Region or a raw coordinate pair,
        // never neither. Same "exactly what it claims, nothing silently missing" discipline as
        // threshold_configs_exactly_one_target.
        DB::statement(
            'ALTER TABLE portfolio_locations ADD CONSTRAINT portfolio_location_has_a_place '.
            'CHECK (region_id IS NOT NULL OR (latitude IS NOT NULL AND longitude IS NOT NULL))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_locations');
        Schema::dropIfExists('portfolios');
    }
};
