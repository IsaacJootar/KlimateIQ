<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->increments('region_id');
            $table->string('name', 150);
            $table->string('state', 100);
            $table->string('lga_code', 20)->unique();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->unsignedBigInteger('population')->nullable();
            $table->timestamps();
        });

        Schema::create('signal_types', function (Blueprint $table) {
            $table->smallIncrements('signal_type_id');
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('unit', 30)->nullable();
            $table->string('source', 100)->nullable();
        });

        Schema::create('indices', function (Blueprint $table) {
            $table->smallIncrements('index_id');
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indices');
        Schema::dropIfExists('signal_types');
        Schema::dropIfExists('regions');
    }
};
