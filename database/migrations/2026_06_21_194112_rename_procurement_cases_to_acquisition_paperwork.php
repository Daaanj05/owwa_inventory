<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('procurement_cases') && ! Schema::hasTable('acquisition_paperwork')) {
            Schema::rename('procurement_cases', 'acquisition_paperwork');
        }

        if (Schema::hasTable('procurement_case_lines') && ! Schema::hasTable('acquisition_paperwork_lines')) {
            Schema::rename('procurement_case_lines', 'acquisition_paperwork_lines');
        }

        if (Schema::hasColumn('acquisition_paperwork_lines', 'procurement_case_id')) {
            $this->dropForeignKeyIfExists('acquisition_paperwork_lines', [
                'procurement_case_lines_procurement_case_id_foreign',
                'acquisition_paperwork_lines_procurement_case_id_foreign',
            ], 'procurement_case_id');

            Schema::table('acquisition_paperwork_lines', function (Blueprint $table): void {
                $table->renameColumn('procurement_case_id', 'acquisition_paperwork_id');
            });
        }

        if (Schema::hasColumn('acquisition_paperwork_lines', 'acquisition_paperwork_id')
            && ! $this->foreignKeyExists('acquisition_paperwork_lines', 'acquisition_paperwork_id')) {
            Schema::table('acquisition_paperwork_lines', function (Blueprint $table): void {
                $table->foreign('acquisition_paperwork_id')
                    ->references('id')
                    ->on('acquisition_paperwork')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('acquisition_paperwork', 'acquired_at')) {
            Schema::table('acquisition_paperwork', function (Blueprint $table): void {
                $table->dropColumn(['acquired_at']);
            });
        }

        if (Schema::hasColumn('acquisition_paperwork_lines', 'acquisition_id')) {
            $this->dropForeignKeyIfExists('acquisition_paperwork_lines', [
                'procurement_case_lines_acquisition_id_foreign',
                'acquisition_paperwork_lines_acquisition_id_foreign',
            ], 'acquisition_id');

            Schema::table('acquisition_paperwork_lines', function (Blueprint $table): void {
                $table->dropColumn('acquisition_id');
            });
        }

        DB::table('reference_series')->where('type', 'procurement_pr')->update([
            'type' => 'acquisition_paperwork_pr',
            'name' => 'Purchase request (Appendix 60 PR)',
        ]);
        DB::table('reference_series')->where('type', 'procurement_po')->update([
            'type' => 'acquisition_paperwork_po',
            'name' => 'Purchase order (Appendix 61 PO)',
        ]);
        DB::table('reference_series')->where('type', 'procurement_iar')->update([
            'type' => 'acquisition_paperwork_iar',
            'name' => 'Inspection and acceptance (Appendix 62 IAR)',
        ]);
    }

    public function down(): void
    {
        DB::table('reference_series')->where('type', 'acquisition_paperwork_pr')->update(['type' => 'procurement_pr']);
        DB::table('reference_series')->where('type', 'acquisition_paperwork_po')->update(['type' => 'procurement_po']);
        DB::table('reference_series')->where('type', 'acquisition_paperwork_iar')->update(['type' => 'procurement_iar']);

        if (! Schema::hasTable('acquisition_paperwork_lines')) {
            return;
        }

        if (! Schema::hasColumn('acquisition_paperwork_lines', 'acquisition_id')) {
            Schema::table('acquisition_paperwork_lines', function (Blueprint $table): void {
                $table->foreignId('acquisition_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('acquisition_paperwork', 'acquired_at')) {
            Schema::table('acquisition_paperwork', function (Blueprint $table): void {
                $table->timestamp('acquired_at')->nullable();
            });
        }

        if (Schema::hasColumn('acquisition_paperwork_lines', 'acquisition_paperwork_id')) {
            $this->dropForeignKeyIfExists('acquisition_paperwork_lines', [
                'acquisition_paperwork_lines_acquisition_paperwork_id_foreign',
            ], 'acquisition_paperwork_id');

            Schema::table('acquisition_paperwork_lines', function (Blueprint $table): void {
                $table->renameColumn('acquisition_paperwork_id', 'procurement_case_id');
            });
        }

        if (Schema::hasColumn('acquisition_paperwork_lines', 'procurement_case_id')) {
            Schema::table('acquisition_paperwork_lines', function (Blueprint $table): void {
                $table->foreign('procurement_case_id')
                    ->references('id')
                    ->on('acquisition_paperwork')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('acquisition_paperwork_lines')) {
            Schema::rename('acquisition_paperwork_lines', 'procurement_case_lines');
        }

        if (Schema::hasTable('acquisition_paperwork')) {
            Schema::rename('acquisition_paperwork', 'procurement_cases');
        }
    }

    /**
     * @param  array<int, string>  $constraintNames
     */
    protected function dropForeignKeyIfExists(string $table, array $constraintNames, ?string $column = null): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            if ($column === null || ! Schema::hasColumn($table, $column)) {
                return;
            }

            try {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropForeign([$column]);
                });
            } catch (\Throwable) {
                //
            }

            return;
        }

        if ($driver === 'pgsql') {
            $existing = collect(DB::select(
                'SELECT constraint_name FROM information_schema.table_constraints
                 WHERE table_schema = current_schema()
                 AND table_name = ?
                 AND constraint_type = ?',
                [$table, 'FOREIGN KEY']
            ))->pluck('constraint_name')->all();
        } else {
            $existing = collect(DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ?
                 AND CONSTRAINT_TYPE = ?',
                [$table, 'FOREIGN KEY']
            ))->pluck('CONSTRAINT_NAME')->all();
        }

        foreach ($constraintNames as $name) {
            if (! in_array($name, $existing, true)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropForeign($name);
            });

            return;
        }
    }

    protected function foreignKeyExists(string $table, string $column): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return false;
        }

        if ($driver === 'pgsql') {
            return collect(DB::select(
                'SELECT tc.constraint_name
                 FROM information_schema.table_constraints AS tc
                 INNER JOIN information_schema.key_column_usage AS kcu
                     ON tc.constraint_name = kcu.constraint_name
                     AND tc.table_schema = kcu.table_schema
                 WHERE tc.constraint_type = ?
                 AND tc.table_schema = current_schema()
                 AND kcu.table_name = ?
                 AND kcu.column_name = ?',
                ['FOREIGN KEY', $table, $column]
            ))->isNotEmpty();
        }

        return collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME = ?
             AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        ))->isNotEmpty();
    }
};
