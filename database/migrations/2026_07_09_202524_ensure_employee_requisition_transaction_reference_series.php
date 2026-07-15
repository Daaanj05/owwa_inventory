<?php

use App\Models\ReferenceSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reference_series', function (Blueprint $table): void {
            $table->string('type', 50)->change();
        });

        $type = ReferenceSeries::TYPE_EMPLOYEE_REQUISITION_TRANSACTION;

        if (DB::table('reference_series')->where('type', $type)->exists()) {
            return;
        }

        $now = now();

        DB::table('reference_series')->insert([
            'type' => $type,
            'name' => 'Employee requisition transaction no.',
            'prefix' => 'TXN',
            'pattern' => '{Y}-01-{seq:4}',
            'next_sequence' => 1,
            'reset_period' => ReferenceSeries::RESET_YEARLY,
            'last_generated_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed.
    }
};
