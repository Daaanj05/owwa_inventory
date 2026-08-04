<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\ReferenceSeriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KeepSystemAdminOnlyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_keeps_system_admin_and_deletes_other_users(): void
    {
        $this->seed(ReferenceSeriesSeeder::class);

        $office = Office::factory()->create();

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email' => 'admin@example.com',
            'office_id' => $office->id,
        ]);

        User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email' => 'custodian@example.com',
            'office_id' => $office->id,
        ]);

        User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'employee@example.com',
            'office_id' => $office->id,
        ]);

        Item::factory()->create();

        $this->artisan('owwa:keep-system-admin-only', [
            '--clear-offices' => true,
            '--email' => 'uat-admin@example.com',
            '--password' => 'SecretPass123!',
        ])->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Office::query()->count());
        $this->assertSame(0, Item::query()->count());

        $admin->refresh();
        $this->assertSame(User::ROLE_SYSTEM_ADMIN, $admin->role);
        $this->assertSame('uat-admin@example.com', $admin->email);
        $this->assertNull($admin->office_id);
        $this->assertFalse($admin->must_change_password);
        $this->assertTrue(Hash::check('SecretPass123!', $admin->password));
    }

    public function test_refuses_production_without_force(): void
    {
        User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        $this->app['env'] = 'production';

        $this->artisan('owwa:keep-system-admin-only')
            ->assertFailed();
    }
}
