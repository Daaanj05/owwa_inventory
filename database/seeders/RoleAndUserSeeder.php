<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleAndUserSeeder extends Seeder
{
    public function run(): void
    {
        $regional = Office::firstOrCreate(
            ['code' => 'OWWA-IVA'],
            ['name' => 'OWWA Regional Office IV-A', 'is_satellite' => false, 'address' => 'CALABARZON']
        );

        User::updateOrCreate(
            ['email' => 'admin@owwa.gov.ph'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_SYSTEM_ADMIN,
                'office_id' => $regional->id,
            ]
        );

        User::updateOrCreate(
            ['email' => 'custodian@owwa.gov.ph'],
            [
                'name' => 'Supply Custodian',
                'password' => Hash::make('password'),
                'role' => User::ROLE_SUPPLY_CUSTODIAN,
                'office_id' => $regional->id,
            ]
        );

        User::updateOrCreate(
            ['email' => 'authorized@owwa.gov.ph'],
            [
                'name' => 'Unit Head',
                'password' => Hash::make('password'),
                'role' => User::ROLE_AUTHORIZED_PERSONNEL,
                'office_id' => $regional->id,
            ]
        );
    }
}
