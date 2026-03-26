<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            // Human-readable label, e.g. "2024", "2024–2025"
            $table->string('name', 50);
            // Date range this fiscal year covers
            $table->date('start_date');
            $table->date('end_date');
            // Optional: allow marking one as default
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};

