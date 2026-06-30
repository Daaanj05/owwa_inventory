<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fiscal_years');
    }

    public function down(): void
    {
        // Fiscal year support was removed; restoring the table is not supported.
    }
};
