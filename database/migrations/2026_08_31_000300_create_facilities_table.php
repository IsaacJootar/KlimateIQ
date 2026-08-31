<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clarity Pass D1 — a local register of real places (health facilities, schools, markets, water
 * points, shelters) per LGA, so a recommendation can name a few concrete examples instead of
 * saying "notify local facilities". Imported from GRID3 Nigeria (open, CC-BY); every use of it
 * in the UI is framed as examples on record, to be verified locally — the platform points, it
 * does not certify.
 *
 * The source is behind App\Services\Facilities\FacilityProvider — swapping GRID3's static table
 * for a live API later is a new provider class plus one config line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->nullable()->constrained('regions', 'region_id')->nullOnDelete();
            $table->string('name', 160);
            $table->string('type', 20);       // health | school | market | water_point | shelter
            $table->string('category', 80)->nullable(); // e.g. 'Primary Health Centre', 'Secondary School'
            $table->string('state', 100)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('source', 40)->default('GRID3');
            $table->unsignedSmallInteger('source_year')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['region_id', 'type', 'name']);
            $table->index(['region_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
