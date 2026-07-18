<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disposals', function (Blueprint $table): void {
            $table->decimal('accumulated_depreciation', 15, 2)->nullable()->after('acquisition_cost');
            $table->decimal('accumulated_impairment_losses', 15, 2)->nullable()->after('accumulated_depreciation');
            $table->decimal('appraised_value', 15, 2)->nullable()->after('accumulated_impairment_losses');
            $table->decimal('iirup_disposal_amount', 15, 2)->nullable()->after('iirup_disposal_mode');
            $table->string('iirup_other_mode')->nullable()->after('iirup_disposal_amount');
            $table->string('authorized_official_designation')->nullable()->after('approved_by_printed_name');
        });
    }

    public function down(): void
    {
        Schema::table('disposals', function (Blueprint $table): void {
            $table->dropColumn([
                'accumulated_depreciation',
                'accumulated_impairment_losses',
                'appraised_value',
                'iirup_disposal_amount',
                'iirup_other_mode',
                'authorized_official_designation',
            ]);
        });
    }
};
