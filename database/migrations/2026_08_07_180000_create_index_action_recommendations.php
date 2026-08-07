<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('index_action_recommendations', function (Blueprint $table) {
            $table->smallIncrements('recommendation_id');
            $table->unsignedSmallInteger('index_id');
            $table->string('risk_band', 10);
            $table->text('action_text');
            $table->timestamps();
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
            $table->unique(['index_id', 'risk_band']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_action_recommendations');
    }
};
