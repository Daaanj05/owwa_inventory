<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('remember_token');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
            $table->boolean('has_email_authentication')->default(false)->after('app_authentication_recovery_codes');
            $table->string('email_verification_code_hash')->nullable()->after('has_email_authentication');
            $table->timestamp('email_verification_code_expires_at')->nullable()->after('email_verification_code_hash');
        });

        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->string('reset_code_hash')->nullable()->after('handled_at');
            $table->timestamp('reset_code_expires_at')->nullable()->after('reset_code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'app_authentication_secret',
                'app_authentication_recovery_codes',
                'has_email_authentication',
                'email_verification_code_hash',
                'email_verification_code_expires_at',
            ]);
        });

        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropColumn([
                'reset_code_hash',
                'reset_code_expires_at',
            ]);
        });
    }
};
