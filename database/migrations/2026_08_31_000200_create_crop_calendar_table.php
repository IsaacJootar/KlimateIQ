<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clarity Pass D2 — which crops are in a water-sensitive growth stage, where, and when. Rows
 * key on a scope: 'zone' (an agro-ecological zone) now, 'state' later if officers want a
 * sharper local picture. A 'state' row overrides a 'zone' row for the same crop without a
 * migration. Reference data — seeded, never edited from the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_calendar', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10);          // 'zone' | 'state'
            $table->string('scope_key', 80);      // e.g. 'Sudan Savanna' or 'Kano'
            $table->string('crop', 60);
            $table->string('stage', 80);          // the water-sensitive stage, e.g. 'grain-fill'
            $table->json('sensitive_months');     // [7, 8, 9] — months that stage typically falls in
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['scope', 'scope_key', 'crop']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_calendar');
    }
};
