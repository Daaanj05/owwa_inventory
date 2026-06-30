<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->string('supply_custodian_name')->nullable()->after('fund_cluster');
            $table->string('supply_custodian_designation')->nullable()->after('supply_custodian_name');
            $table->string('authorized_officer_name')->nullable()->after('supply_custodian_designation');
            $table->string('authorized_officer_designation')->nullable()->after('authorized_officer_name');
            $table->string('accountable_officer_name')->nullable()->after('authorized_officer_designation');
            $table->string('accountable_officer_designation')->nullable()->after('accountable_officer_name');
            $table->string('inspection_officer_name')->nullable()->after('accountable_officer_designation');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn([
                'supply_custodian_name',
                'supply_custodian_designation',
                'authorized_officer_name',
                'authorized_officer_designation',
                'accountable_officer_name',
                'accountable_officer_designation',
                'inspection_officer_name',
            ]);
        });
    }
};
