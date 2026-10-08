<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PrepareLoadTestUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::delete(base_path('loadtests/.credentials.json'));

        parent::tearDown();
    }

    public function test_prepare_creates_verified_users_and_credentials_file(): void
    {
        $this->artisan('loadtest:prepare', ['--count' => 3])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'loadtest.user01@example.test',
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'must_change_password' => false,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'loadtest.user03@example.test',
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $path = base_path('loadtests/.credentials.json');
        $this->assertFileExists($path);

        $payload = json_decode(File::get($path), true);
        $this->assertCount(3, $payload['users'] ?? []);
        $this->assertSame('/login', $payload['login_path'] ?? null);

        $user = User::query()->where('email', 'loadtest.user01@example.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_prepare_is_blocked_outside_local_without_force_flag(): void
    {
        $this->app['env'] = 'production';
        config(['inventory.load_test_enabled' => false]);

        $this->artisan('loadtest:prepare')
            ->assertFailed();
    }
}
