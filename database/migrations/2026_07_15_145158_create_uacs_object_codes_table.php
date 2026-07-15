<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uacs_object_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64);
            $table->string('name');
            $table->string('property_class', 64)->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uacs_object_codes');
    }
};
