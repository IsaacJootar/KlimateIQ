<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sectors are a grouping layer over the existing indices — "Public Health", "Agriculture",
 * etc. A user picks the sector(s) that match their job, and that expands to the underlying
 * `user_index_subscriptions` rows the rest of the app already understands (see
 * App\Support\IndexCoverage). No downstream scoring/alerting change — this is a label layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->smallIncrements('sector_id');
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            // The always-pre-ticked sector on the setup wizard (the "Overview" snapshot).
            $table->boolean('is_default')->default(false);
        });

        Schema::create('index_sector', function (Blueprint $table) {
            $table->unsignedSmallInteger('index_id');
            $table->unsignedSmallInteger('sector_id');
            // Optional visual sub-heading within a sector (e.g. "Vector-borne" under Public
            // Health) — a display hint only, not a selectable entity.
            $table->string('theme', 60)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['index_id', 'sector_id']);
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
            $table->foreign('sector_id')->references('sector_id')->on('sectors')->cascadeOnDelete();
        });

        Schema::create('user_sector_subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sector_id');
            $table->primary(['user_id', 'sector_id']);
            $table->foreign('sector_id')->references('sector_id')->on('sectors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sector_subscriptions');
        Schema::dropIfExists('index_sector');
        Schema::dropIfExists('sectors');
    }
};
