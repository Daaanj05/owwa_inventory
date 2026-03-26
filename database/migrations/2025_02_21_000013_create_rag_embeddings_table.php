<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('source')->index(); // inventory_context | document
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->json('embedding'); // vector stored as JSON array for MySQL
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_embeddings');
    }
};
