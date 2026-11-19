<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Services\PasswordResetCodeService;
use App\Support\OneTimeCode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_reset_email_contains_code(): void
    {
        Notification::fake();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'employee@example.com',
            'email_verified_at' => now(),
        ]);

        $result = app(PasswordResetCodeService::class)->issueAndSendForEmail($employee->email);

        $this->assertTrue($result->success);
        Notification::assertSentTo($employee, PasswordResetCodeNotification::class);
        $this->assertDatabaseHas('password_reset_requests', [
            'user_id' => $employee->id,
            'status' => PasswordResetRequest::STATUS_SENT,
        ]);
    }

    public function test_reset_code_sets_new_password_on_forgot_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'reset.code@example.com',
        ]);

        $code = '654321';
        PasswordResetRequest::query()->create([
            'user_id' => $user->id,
            'status' => PasswordResetRequest::STATUS_SENT,
            'requested_at' => now(),
            'handled_at' => now(),
            'reset_code_hash' => OneTimeCode::hash($code),
            'reset_code_expires_at' => now()->addHour(),
        ]);

        Livewire::test(RequestPasswordReset::class)
            ->set('step', 2)
            ->set('data', [
                'email' => 'reset.code@example.com',
                'code' => $code,
            ])
            ->fillForm([
                'code' => $code,
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
            ])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user->refresh();
        $this->assertFalse($user->mustChangePassword());
        $this->assertTrue(Hash::check('NewPass1', $user->password));
    }

    public function test_reset_code_is_invalidated_after_too_many_wrong_guesses(): void
    {
        RateLimiter::clear('password-reset-code:'.sha1(strtolower('lock.code@example.com')));

        $user = User::factory()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'lock.code@example.com',
        ]);

        $code = '654321';
        $request = PasswordResetRequest::query()->create([
            'user_id' => $user->id,
            'status' => PasswordResetRequest::STATUS_SENT,
            'requested_at' => now(),
            'handled_at' => now(),
            'reset_code_hash' => OneTimeCode::hash($code),
            'reset_code_expires_at' => now()->addHour(),
        ]);

        $service = app(PasswordResetCodeService::class);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->resetWithCode('lock.code@example.com', '000000', 'NewPass1!'));
        }

        $request->refresh();
        $this->assertNull($request->reset_code_hash);
        $this->assertNull($request->reset_code_expires_at);
        $this->assertFalse($service->resetWithCode('lock.code@example.com', $code, 'NewPass1!'));

        Notification::fake();
        $service->issueAndSend($request->fresh());

        $sentCode = null;
        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function (PasswordResetCodeNotification $notification) use (&$sentCode): bool {
            $sentCode = $notification->code;

            return true;
        });

        $this->assertNotNull($sentCode);
        $this->assertTrue($service->resetWithCode('lock.code@example.com', $sentCode, 'NewPass1!'));
        $this->assertTrue(Hash::check('NewPass1!', $user->fresh()->password));
    }
}
