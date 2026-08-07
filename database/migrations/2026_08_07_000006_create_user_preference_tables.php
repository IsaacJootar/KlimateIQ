<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_dashboard_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('default_view', 20)->default('list');
            $table->string('preferred_alert_channel', 20)->default('email');
            $table->timestamps();
        });

        Schema::create('user_index_subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('index_id');
            $table->boolean('wants_alerts')->default(true);
            $table->primary(['user_id', 'index_id']);
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
        });

        Schema::create('user_region_subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('region_id');
            $table->primary(['user_id', 'region_id']);
            $table->foreign('region_id')->references('region_id')->on('regions')->cascadeOnDelete();
        });

        Schema::create('saved_views', function (Blueprint $table) {
            $table->uuid('saved_view_id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedSmallInteger('index_id')->nullable();
            $table->json('region_ids')->nullable();
            $table->json('view_config')->nullable();
            $table->timestamps();
            $table->foreign('index_id')->references('index_id')->on('indices')->nullOnDelete();
        });

        Schema::create('report_requests', function (Blueprint $table) {
            $table->uuid('report_request_id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('index_id');
            $table->json('region_ids');
            $table->date('date_from');
            $table->date('date_to');
            $table->string('format', 10)->default('csv');
            $table->string('status', 20)->default('PENDING');
            $table->string('file_path', 255)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->foreign('index_id')->references('index_id')->on('indices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_requests');
        Schema::dropIfExists('saved_views');
        Schema::dropIfExists('user_region_subscriptions');
        Schema::dropIfExists('user_index_subscriptions');
        Schema::dropIfExists('user_dashboard_preferences');
    }
};
