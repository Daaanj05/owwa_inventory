<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\VerifyEmailWithCode;
use App\Models\Office;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Services\EmailVerificationCodeService;
use App\Support\OneTimeCode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class EmailVerificationCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_code_issues_verification_code_notification(): void
    {
        Notification::fake();

        $office = Office::factory()->create();
        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        app(EmailVerificationCodeService::class)->sendCode($user);

        Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function (EmailVerificationCodeNotification $notification): bool {
            return strlen($notification->code) === 6;
        });

        $this->assertNotNull($user->fresh()->email_verification_code_hash);
    }

    public function test_guest_can_complete_setup_with_code_and_password(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->unverified()->withoutMultiFactorAuthentication()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'email' => 'verify.code@example.com',
        ]);

        $code = '123456';
        $user->forceFill([
            'email_verification_code_hash' => OneTimeCode::hash($code),
            'email_verification_code_expires_at' => now()->addHour(),
        ])->save();

        Livewire::test(VerifyEmailWithCode::class)
            ->set('step', 2)
            ->set('data', [
                'email' => 'verify.code@example.com',
                'code' => $code,
            ])
            ->call('submitCode')
            ->assertHasNoFormErrors()
            ->assertSet('step', 3)
            ->fillForm([
                'password' => 'ChosenPass1!',
                'passwordConfirmation' => 'ChosenPass1!',
            ])
            ->call('submitPassword')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_code_hash);
        $this->assertTrue(Hash::check('ChosenPass1!', $user->password));
    }

    public function test_invalid_verification_code_is_rejected(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'bad.code@example.com',
        ]);

        $user->forceFill([
            'email_verification_code_hash' => OneTimeCode::hash('123456'),
            'email_verification_code_expires_at' => now()->addHour(),
        ])->save();

        Livewire::test(VerifyEmailWithCode::class)
            ->set('step', 2)
            ->set('data', [
                'email' => 'bad.code@example.com',
                'code' => '000000',
            ])
            ->call('submitCode')
            ->assertHasFormErrors(['code']);

        $this->assertNull($user->fresh()->email_verified_at);
    }
}
