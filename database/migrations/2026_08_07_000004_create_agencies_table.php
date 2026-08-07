<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->uuid('agency_id')->primary();
            $table->string('name', 200);
            $table->string('type', 100)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('agency_id')->nullable()->after('id');
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });

        Schema::dropIfExists('agencies');
    }
};
