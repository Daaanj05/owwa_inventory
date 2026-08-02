<?php

namespace Tests\Feature;

use App\Listeners\LogFailedLogin;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogFailedLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_for_unknown_email_is_logged_without_user_id(): void
    {
        $event = new Failed('web', null, [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        app(LogFailedLogin::class)->handle($event);

        $this->assertDatabaseHas(UserActivityLog::class, [
            'user_id' => null,
            'action' => 'login_failed',
            'summary' => 'Failed login attempt for nobody@example.com',
        ]);
    }

    public function test_failed_login_for_known_user_attaches_user_id(): void
    {
        $user = User::factory()->create([
            'email' => 'custodian@owwa.gov.ph',
        ]);

        $event = new Failed('web', $user, [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        app(LogFailedLogin::class)->handle($event);

        $this->assertDatabaseHas(UserActivityLog::class, [
            'user_id' => $user->id,
            'action' => 'login_failed',
            'summary' => 'Failed login attempt for custodian@owwa.gov.ph',
        ]);
    }
}
