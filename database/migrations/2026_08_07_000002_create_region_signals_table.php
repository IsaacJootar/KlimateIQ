<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_signals', function (Blueprint $table) {
            $table->bigIncrements('region_signal_id');
            $table->unsignedInteger('region_id');
            $table->unsignedSmallInteger('signal_type_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('value', 12, 4);
            $table->json('raw_metadata')->nullable();
            $table->string('source', 100);
            $table->timestamp('ingested_at')->useCurrent();
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
            $table->foreign('signal_type_id')->references('signal_type_id')->on('signal_types');
            $table->unique(['region_id', 'signal_type_id', 'period_start', 'period_end'], 'region_signals_unique_period');
            $table->index(['region_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_signals');
    }
};
