<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('reference_series')
            ->where('pattern', '{Y}-01-{seq:4}')
            ->update(['pattern' => '{Y}-{m}-{seq:4}']);
    }

    public function down(): void
    {
        DB::table('reference_series')
            ->where('pattern', '{Y}-{m}-{seq:4}')
            ->update(['pattern' => '{Y}-01-{seq:4}']);
    }
};
