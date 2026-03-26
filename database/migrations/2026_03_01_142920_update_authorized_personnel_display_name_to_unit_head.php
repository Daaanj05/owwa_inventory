<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::query()
            ->where('role', User::ROLE_AUTHORIZED_PERSONNEL)
            ->where('name', 'Authorized Personnel')
            ->update(['name' => 'Unit Head']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        User::query()
            ->where('role', User::ROLE_AUTHORIZED_PERSONNEL)
            ->where('name', 'Unit Head')
            ->update(['name' => 'Authorized Personnel']);
    }
};
