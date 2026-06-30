<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_paperwork', function (Blueprint $table): void {
            $table->string('pr_status', 20)->default('draft')->after('phase');
            $table->string('po_status', 20)->default('draft')->after('pr_status');
            $table->string('iar_status', 20)->default('draft')->after('po_status');
            $table->timestamp('pr_submitted_at')->nullable()->after('pr_completed_at');
            $table->timestamp('po_submitted_at')->nullable()->after('po_completed_at');
            $table->timestamp('iar_submitted_at')->nullable()->after('iar_completed_at');
            $table->timestamp('received_at')->nullable()->after('iar_submitted_at');
        });

        Schema::table('acquisitions', function (Blueprint $table): void {
            $table->foreignId('acquisition_paperwork_id')
                ->nullable()
                ->after('id')
                ->constrained('acquisition_paperwork')
                ->nullOnDelete();
            $table->foreignId('acquisition_paperwork_line_id')
                ->nullable()
                ->after('acquisition_paperwork_id')
                ->constrained('acquisition_paperwork_lines')
                ->nullOnDelete();
        });

        if (Schema::hasTable('acquisition_paperwork')) {
            DB::table('acquisition_paperwork')->whereNotNull('pr_completed_at')->update(['pr_status' => 'approved']);
            DB::table('acquisition_paperwork')->whereNotNull('po_completed_at')->update(['po_status' => 'approved']);
            DB::table('acquisition_paperwork')->whereNotNull('iar_completed_at')->update(['iar_status' => 'approved']);
        }
    }

    public function down(): void
    {
        Schema::table('acquisitions', function (Blueprint $table): void {
            $table->dropForeign(['acquisition_paperwork_line_id']);
            $table->dropForeign(['acquisition_paperwork_id']);
            $table->dropColumn(['acquisition_paperwork_line_id', 'acquisition_paperwork_id']);
        });

        Schema::table('acquisition_paperwork', function (Blueprint $table): void {
            $table->dropColumn([
                'pr_status',
                'po_status',
                'iar_status',
                'pr_submitted_at',
                'po_submitted_at',
                'iar_submitted_at',
                'received_at',
            ]);
        });
    }
};
