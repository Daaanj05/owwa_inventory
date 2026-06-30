<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::query()
            ->where('role', 'authorized_personnel')
            ->where('name', 'Authorized Personnel')
            ->update(['name' => 'Unit Head']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        User::query()
            ->where('role', 'authorized_personnel')
            ->where('name', 'Unit Head')
            ->update(['name' => 'Authorized Personnel']);
    }
};
