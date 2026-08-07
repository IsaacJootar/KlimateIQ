<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_types', function (Blueprint $table) {
            // Most signals score worse as the value rises (more rain, more standing water).
            // Elevation is the opposite — lower ground floods first — so normalization needs
            // to know which direction is bad, per signal, rather than assuming one direction.
            $table->boolean('higher_is_worse')->default(true)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('signal_types', function (Blueprint $table) {
            $table->dropColumn('higher_is_worse');
        });
    }
};
