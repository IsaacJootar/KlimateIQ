<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_views', function (Blueprint $table) {
            // Null = private to the creator (default). Set = visible to every member of that
            // agency, not just the creator — an opt-in share, not a change of ownership.
            $table->uuid('agency_id')->nullable()->after('user_id');
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->nullOnDelete();
        });

        Schema::table('report_requests', function (Blueprint $table) {
            $table->uuid('agency_id')->nullable()->after('user_id');
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_requests', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        Schema::table('saved_views', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });
    }
};
