<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_log_id')->nullable()->constrained('user_logs')->nullOnDelete();
            $table->string('action', 64);
            $table->nullableMorphs('subject');
            $table->string('summary');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('panel')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_log_id', 'created_at']);
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');
    }
};
