<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('captured_at')->index();
            $table->unsignedInteger('open_sessions')->default(0);
            $table->unsignedInteger('active_sessions')->default(0);
            $table->unsignedInteger('laravel_sessions')->nullable();
            $table->unsignedInteger('pending_jobs')->nullable();
            $table->unsignedInteger('failed_jobs')->nullable();
            $table->boolean('checks_ok')->default(true);
            $table->json('checks_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_health_snapshots');
    }
};
