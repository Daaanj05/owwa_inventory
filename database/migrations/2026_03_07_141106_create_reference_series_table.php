<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_series', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->unique()->comment('acquisition, issuance, transfer, disposal, requisition');
            $table->string('name', 100)->nullable()->comment('Display label e.g. RIS (Issuance)');
            $table->string('prefix', 20)->comment('e.g. RIS, PTR, REQ');
            $table->string('pattern', 100)->comment('e.g. {prefix}-{Y}-{seq:4}. Placeholders: {prefix}, {Y}, {m}, {d}, {seq}, {seq:N}');
            $table->unsignedInteger('next_sequence')->default(1);
            $table->string('reset_period', 20)->default('yearly')->comment('none, daily, monthly, yearly');
            $table->date('last_generated_at')->nullable()->comment('Date of last generated code; used for reset');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_series');
    }
};
