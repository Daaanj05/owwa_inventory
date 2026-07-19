<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleAndUserSeeder extends Seeder
{
    public function run(): void
    {
        $regional = Office::query()->updateOrCreate(
            ['code' => 'OWWA-IVA'],
            [
                'name' => 'OWWA Regional Office IV-A',
                'is_satellite' => false,
                'is_regional_supply' => true,
                'address' => 'CALABARZON',
            ],
        );

        $useRandomPasswords = app()->environment('production');
        $adminPassword = $useRandomPasswords ? Str::password(20) : 'password';
        $custodianPassword = $useRandomPasswords ? Str::password(20) : 'password';
        $consolidatorPassword = $useRandomPasswords ? Str::password(20) : 'password';

        User::updateOrCreate(
            ['email' => 'admin@owwa.gov.ph'],
            [
                'name' => 'System Admin',
                'password' => $adminPassword,
                'role' => User::ROLE_SYSTEM_ADMIN,
                'office_id' => $regional->id,
                'email_verified_at' => now(),
                'must_change_password' => $useRandomPasswords,
            ]
        );

        User::updateOrCreate(
            ['email' => 'custodian@owwa.gov.ph'],
            [
                'name' => 'Supply Custodian',
                'password' => $custodianPassword,
                'role' => User::ROLE_SUPPLY_CUSTODIAN,
                'office_id' => $regional->id,
                'email_verified_at' => now(),
                'must_change_password' => $useRandomPasswords,
            ]
        );

        User::updateOrCreate(
            ['email' => 'authorized@owwa.gov.ph'],
            [
                'name' => 'Unit Head',
                'password' => $consolidatorPassword,
                'role' => User::ROLE_UNIT_CONSOLIDATOR,
                'office_id' => $regional->id,
                'email_verified_at' => now(),
                'must_change_password' => $useRandomPasswords,
            ]
        );

        if ($useRandomPasswords && $this->command !== null) {
            $this->command->warn('Production seed generated one-time passwords (change after first login):');
            $this->command->line("  admin@owwa.gov.ph / {$adminPassword}");
            $this->command->line("  custodian@owwa.gov.ph / {$custodianPassword}");
            $this->command->line("  authorized@owwa.gov.ph / {$consolidatorPassword}");
        }
    }
}
