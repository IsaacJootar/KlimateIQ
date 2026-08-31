<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clarity Pass B4 — the nav sector switcher. When a user follows more than one sector they can
 * pin a "current" one; the dashboard, regions pages and their tab strips then scope to just that
 * sector's indices. Null (the default) means "all my sectors", i.e. today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dashboard_preferences', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_sector_id')->nullable()->after('default_view');
            $table->foreign('current_sector_id')->references('sector_id')->on('sectors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_dashboard_preferences', function (Blueprint $table) {
            $table->dropForeign(['current_sector_id']);
            $table->dropColumn('current_sector_id');
        });
    }
};
