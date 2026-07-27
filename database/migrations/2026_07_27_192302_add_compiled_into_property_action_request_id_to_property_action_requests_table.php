<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_action_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('property_action_requests', 'compiled_into_property_action_request_id')) {
                $table->unsignedBigInteger('compiled_into_property_action_request_id')->nullable()->after('replacement_requisition_id');
            }
        });

        Schema::table('property_action_requests', function (Blueprint $table): void {
            $table->foreign('compiled_into_property_action_request_id', 'pareq_compiled_into_fk')
                ->references('id')
                ->on('property_action_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('property_action_requests', function (Blueprint $table): void {
            $table->dropForeign('pareq_compiled_into_fk');
            $table->dropColumn('compiled_into_property_action_request_id');
        });
    }
};
