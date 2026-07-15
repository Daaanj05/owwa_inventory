<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('requisitions', 'transaction_number')) {
            Schema::table('requisitions', function (Blueprint $table) {
                $table->string('transaction_number')->nullable()->unique()->after('reference_code');
            });
        }

        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('reference_code')->nullable()->change();
        });

        $rows = DB::table('requisitions')
            ->join('users', 'requisitions.requested_by', '=', 'users.id')
            ->where('users.role', 'employee')
            ->whereNotNull('requisitions.reference_code')
            ->whereNull('requisitions.transaction_number')
            ->select(['requisitions.id', 'requisitions.reference_code'])
            ->orderBy('requisitions.id')
            ->get();

        foreach ($rows as $row) {
            DB::table('requisitions')
                ->where('id', $row->id)
                ->update([
                    'transaction_number' => $row->reference_code,
                    'reference_code' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('requisitions')
            ->whereNotNull('transaction_number')
            ->select(['id', 'reference_code', 'transaction_number'])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            DB::table('requisitions')
                ->where('id', $row->id)
                ->update([
                    'reference_code' => $row->reference_code ?? $row->transaction_number,
                    'transaction_number' => null,
                    'updated_at' => now(),
                ]);
        }

        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('reference_code')->nullable(false)->change();

            if (Schema::hasColumn('requisitions', 'transaction_number')) {
                $table->dropUnique(['transaction_number']);
                $table->dropColumn('transaction_number');
            }
        });
    }
};
