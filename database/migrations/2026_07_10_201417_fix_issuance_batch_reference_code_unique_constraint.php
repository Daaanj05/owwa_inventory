<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('issuance_batches')) {
            return;
        }

        $this->dropUniqueIndexOnColumns('issuance_batches', ['reference_code']);
        $this->ensureUniqueIndexOnColumns('issuance_batches', ['category_slug', 'reference_code']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('issuance_batches')) {
            return;
        }

        $this->dropUniqueIndexOnColumns('issuance_batches', ['category_slug', 'reference_code']);
        $this->ensureUniqueIndexOnColumns('issuance_batches', ['reference_code']);
    }

    /**
     * @param  list<string>  $columns
     */
    protected function dropUniqueIndexOnColumns(string $table, array $columns): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (! ($index['unique'] ?? false) || ($index['columns'] ?? []) !== $columns) {
                continue;
            }

            $name = (string) ($index['name'] ?? '');
            if ($name === '') {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropUnique($name);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    protected function ensureUniqueIndexOnColumns(string $table, array $columns): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['unique'] ?? false) && ($index['columns'] ?? []) === $columns) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->unique($columns);
        });
    }
};
