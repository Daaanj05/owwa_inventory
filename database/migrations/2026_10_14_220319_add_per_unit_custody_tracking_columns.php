<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table): void {
            $table->json('issued_property_numbers')->nullable()->after('property_number');
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
        });

        Schema::table('inventory_units', function (Blueprint $table): void {
            $table->foreignId('repair_source_issuance_id')
                ->nullable()
                ->after('issuance_id')
                ->constrained('issuances')
                ->nullOnDelete();
        });

        Schema::table('property_action_request_lines', function (Blueprint $table): void {
            $table->string('outcome', 30)->nullable()->after('transfer_id');
        });

        DB::table('inventory_units')
            ->whereNotNull('issuance_id')
            ->whereNotNull('property_number')
            ->orderBy('id')
            ->get(['issuance_id', 'property_number'])
            ->groupBy('issuance_id')
            ->each(function ($units, $issuanceId): void {
                DB::table('issuances')
                    ->where('id', $issuanceId)
                    ->update([
                        'issued_property_numbers' => json_encode(
                            $units->pluck('property_number')->unique()->values()->all(),
                        ),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('property_action_request_lines', function (Blueprint $table): void {
            $table->dropColumn('outcome');
        });

        Schema::table('inventory_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('repair_source_issuance_id');
        });

        Schema::table('issuances', function (Blueprint $table): void {
            $table->dropColumn(['issued_property_numbers', 'returned_quantity']);
        });
    }
};
