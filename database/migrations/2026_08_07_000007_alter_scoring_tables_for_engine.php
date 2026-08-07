<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A period with no available signals must still be recordable (score = null,
        // breakdown explains why) rather than skipped — mirrors sub_index_scores'
        // nullable score for NOT_CALIBRATED periods. Postgres check constraints pass on
        // NULL automatically, so the existing 0-100 check needs no change.
        DB::statement('ALTER TABLE region_scores ALTER COLUMN score DROP NOT NULL');

        Schema::table('regions', function (Blueprint $table) {
            $table->string('preferred_scoring_strategy', 20)->nullable()->after('population');
        });
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn('preferred_scoring_strategy');
        });

        DB::statement('ALTER TABLE region_scores ALTER COLUMN score SET NOT NULL');
    }
};
