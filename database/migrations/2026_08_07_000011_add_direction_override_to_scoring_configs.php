<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('region_scoring_configs', function (Blueprint $table) {
            // signal_types.higher_is_worse is a per-signal default direction. It isn't always
            // right: rainfall is bad-when-high for Flood/Malaria Risk but bad-when-low for
            // Drought Risk. Null here means "use the signal's default"; set it to override
            // direction for this specific index/signal pairing.
            $table->boolean('higher_is_worse')->nullable()->after('vulnerability_weight');
        });
    }

    public function down(): void
    {
        Schema::table('region_scoring_configs', function (Blueprint $table) {
            $table->dropColumn('higher_is_worse');
        });
    }
};
