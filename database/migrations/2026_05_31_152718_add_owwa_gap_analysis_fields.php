<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('days_to_consume')->nullable()->after('reorder_level');
            $table->string('estimated_useful_life')->nullable()->after('days_to_consume');
            $table->string('serial_number')->nullable()->after('estimated_useful_life');
        });

        Schema::table('issuances', function (Blueprint $table) {
            $table->string('estimated_useful_life')->nullable()->after('property_number');
            $table->string('received_from_name')->nullable()->after('estimated_useful_life');
            $table->string('custodian_designation')->nullable()->after('custodian_printed_name');
            $table->string('issued_to_designation')->nullable()->after('issued_to');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->string('transfer_type', 50)->nullable()->after('transfer_date');
            $table->string('transfer_type_other')->nullable()->after('transfer_type');
            $table->text('reason_for_transfer')->nullable()->after('transfer_type_other');
            $table->string('from_accountable_officer')->nullable()->after('to_office_id');
            $table->string('to_accountable_officer')->nullable()->after('from_accountable_officer');
            $table->string('approved_by_designation')->nullable()->after('approved_by_printed_name');
            $table->string('released_by_designation')->nullable()->after('released_by_printed_name');
            $table->string('received_by_designation')->nullable()->after('received_by_printed_name');
        });

        Schema::table('disposals', function (Blueprint $table) {
            $table->string('place_of_storage')->nullable()->after('office_id');
            $table->string('disposal_mode', 50)->nullable()->after('disposal_type');
            $table->unsignedTinyInteger('wmr_inspection_item_no')->nullable()->after('disposal_mode');
            $table->string('accountable_officer_designation')->nullable()->after('custodian_printed_name');
            $table->string('accountable_officer_station')->nullable()->after('accountable_officer_designation');
            $table->text('circumstances')->nullable()->after('reason');
            $table->foreignId('par_issuance_id')->nullable()->after('circumstances')->constrained('issuances')->nullOnDelete();
            $table->boolean('police_notified')->nullable()->after('par_issuance_id');
            $table->string('police_station')->nullable()->after('police_notified');
            $table->date('police_notified_date')->nullable()->after('police_station');
            $table->string('property_status', 50)->nullable()->after('police_notified_date');
            $table->string('gov_id_type')->nullable()->after('witness_printed_name');
            $table->string('gov_id_no')->nullable()->after('gov_id_type');
            $table->date('gov_id_date_issued')->nullable()->after('gov_id_no');
            $table->string('immediate_supervisor_printed_name')->nullable()->after('gov_id_date_issued');
            $table->string('iirup_disposal_mode', 50)->nullable()->after('immediate_supervisor_printed_name');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->text('purpose')->nullable()->after('remarks');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->unsignedInteger('stock_available')->nullable()->after('quantity');
            $table->unsignedInteger('quantity_issued')->nullable()->after('stock_available');
            $table->text('issue_remarks')->nullable()->after('quantity_issued');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn(['stock_available', 'quantity_issued', 'issue_remarks']);
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });

        Schema::table('disposals', function (Blueprint $table) {
            $table->dropForeign(['par_issuance_id']);
            $table->dropColumn([
                'place_of_storage', 'disposal_mode', 'wmr_inspection_item_no',
                'accountable_officer_designation', 'accountable_officer_station',
                'circumstances', 'par_issuance_id', 'police_notified', 'police_station',
                'police_notified_date', 'property_status', 'gov_id_type', 'gov_id_no',
                'gov_id_date_issued', 'immediate_supervisor_printed_name', 'iirup_disposal_mode',
            ]);
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn([
                'transfer_type', 'transfer_type_other', 'reason_for_transfer',
                'from_accountable_officer', 'to_accountable_officer',
                'approved_by_designation', 'released_by_designation', 'received_by_designation',
            ]);
        });

        Schema::table('issuances', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_useful_life', 'received_from_name',
                'custodian_designation', 'issued_to_designation',
            ]);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['days_to_consume', 'estimated_useful_life', 'serial_number']);
        });
    }
};
