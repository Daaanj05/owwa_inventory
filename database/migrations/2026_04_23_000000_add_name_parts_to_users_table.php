<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
        });

        DB::table('users')
            ->select(['id', 'name', 'first_name', 'middle_name', 'last_name'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    // Don’t overwrite if already populated.
                    if ($row->first_name !== null || $row->middle_name !== null || $row->last_name !== null) {
                        continue;
                    }

                    $name = trim((string) ($row->name ?? ''));
                    if ($name === '') {
                        continue;
                    }

                    $parts = preg_split('/\s+/', $name) ?: [];
                    $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

                    $first = $parts[0] ?? null;
                    $last = count($parts) > 1 ? $parts[count($parts) - 1] : null;
                    $middle = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : null;

                    DB::table('users')
                        ->where('id', $row->id)
                        ->update([
                            'first_name' => $first,
                            'middle_name' => $middle,
                            'last_name' => $last,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'middle_name', 'last_name']);
        });
    }
};
