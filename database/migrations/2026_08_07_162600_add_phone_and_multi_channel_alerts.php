<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number', 20)->nullable()->after('platform_role');
        });

        Schema::table('user_dashboard_preferences', function (Blueprint $table) {
            $table->json('alert_channels')->nullable()->default('["in_app"]')->after('preferred_alert_channel');
        });

        // Carry the old single-channel value forward as a one-item array rather than
        // dropping it silently.
        DB::statement("UPDATE user_dashboard_preferences SET alert_channels = json_build_array(preferred_alert_channel) WHERE preferred_alert_channel IS NOT NULL");

        Schema::table('user_dashboard_preferences', function (Blueprint $table) {
            $table->dropColumn('preferred_alert_channel');
        });
    }

    public function down(): void
    {
        Schema::table('user_dashboard_preferences', function (Blueprint $table) {
            $table->string('preferred_alert_channel', 20)->default('email');
        });

        Schema::table('user_dashboard_preferences', function (Blueprint $table) {
            $table->dropColumn('alert_channels');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_number');
        });
    }
};
