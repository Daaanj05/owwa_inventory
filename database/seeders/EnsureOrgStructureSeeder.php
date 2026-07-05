<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Seeder;

class EnsureOrgStructureSeeder extends Seeder
{
    public function run(): void
    {
        $regional = Office::query()->updateOrCreate(
            ['code' => 'OWWA-IVA'],
            [
                'name' => 'OWWA Regional Office IV-A',
                'fund_cluster' => '01',
                'is_satellite' => false,
                'address' => 'CALABARZON',
                'accountable_officer_name' => 'Marita C. Ablis',
                'accountable_officer_designation' => 'Supply Officer',
                'supply_custodian_name' => 'Supply Custodian',
                'authorized_officer_name' => 'Roberto Cruz',
            ],
        );

        $satellite = Office::query()->updateOrCreate(
            ['code' => 'OWWA-LAG'],
            [
                'name' => 'OWWA Satellite Office — Laguna',
                'fund_cluster' => '01',
                'is_satellite' => true,
                'address' => 'Sta. Cruz, Laguna',
                'accountable_officer_name' => 'Pedro Santos',
                'accountable_officer_designation' => 'Satellite Supply Officer',
                'supply_custodian_name' => 'Laguna Custodian',
                'authorized_officer_name' => 'Roberto Cruz',
            ],
        );

        $admin = Department::query()->firstOrCreate(
            ['office_id' => $regional->id, 'name' => 'Administrative Division'],
            ['code' => 'ADM'],
        );

        Department::query()->firstOrCreate(
            ['office_id' => $satellite->id, 'name' => 'Welfare Services Unit'],
            ['code' => 'WSU'],
        );

        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->first();
        $custodian?->update(['office_id' => $regional->id, 'department_id' => $admin->id]);

        User::query()->updateOrCreate(
            ['email' => 'consolidator2@owwa.gov.ph'],
            [
                'name' => 'Roberto Cruz',
                'password' => 'password',
                'role' => User::ROLE_UNIT_CONSOLIDATOR,
                'office_id' => $satellite->id,
                'department_id' => Department::query()
                    ->where('office_id', $satellite->id)
                    ->where('name', 'Welfare Services Unit')
                    ->value('id'),
                'email_verified_at' => now(),
            ],
        );
    }
}
