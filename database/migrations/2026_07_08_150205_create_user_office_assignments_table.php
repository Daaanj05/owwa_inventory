<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_office_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'office_id', 'department_id'], 'user_office_assignments_unique');
        });

        if (! Schema::hasTable('users')) {
            return;
        }

        $rows = DB::table('users')
            ->where('role', 'unit_consolidator')
            ->whereNotNull('office_id')
            ->whereNotNull('department_id')
            ->get(['id', 'office_id', 'department_id']);

        $now = now();

        foreach ($rows as $row) {
            DB::table('user_office_assignments')->insertOrIgnore([
                'user_id' => $row->id,
                'office_id' => $row->office_id,
                'department_id' => $row->department_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_office_assignments');
    }
};
