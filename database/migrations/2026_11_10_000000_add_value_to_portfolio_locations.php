<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise pivot (docs/ENTERPRISE_BUILD_PLAN.md) — gives each portfolio asset an optional
 * monetary value, so a portfolio can show "₦2,000,000 of loans sit in red-band LGAs" instead of
 * just a risk band with no stake attached. Optional and per-asset: a client may not have (or want
 * to enter) a figure for every location, and different assets in the same book can carry
 * different currencies (a lender's naira loan book vs. a reinsurer's dollar-denominated cover).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_locations', function (Blueprint $table) {
            $table->decimal('value', 18, 2)->nullable()->after('label');
            $table->string('currency', 3)->nullable()->after('value'); // ISO 4217: 'NGN' or 'USD'
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_locations', function (Blueprint $table) {
            $table->dropColumn(['value', 'currency']);
        });
    }
};
