<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('physical_count_lines', 'pc_lines_session_property_unique')) {
            Schema::table('physical_count_lines', function (Blueprint $table) {
                $table->unique(
                    ['physical_count_session_id', 'property_number'],
                    'pc_lines_session_property_unique'
                );
            });
        }

        if (! Schema::hasTable('physical_count_scan_events')) {
            Schema::create('physical_count_scan_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('physical_count_session_id')->constrained()->cascadeOnDelete();
                $table->string('property_number');
                $table->string('result', 20);
                $table->foreignId('physical_count_line_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('scanned_at');
                $table->timestamps();

                $table->index(['physical_count_session_id', 'scanned_at'], 'pc_scan_events_session_scanned_idx');
            });
        }
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['name'] ?? '') === $indexName) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_count_scan_events');

        Schema::table('physical_count_lines', function (Blueprint $table) {
            $table->dropUnique('pc_lines_session_property_unique');
        });
    }
};
