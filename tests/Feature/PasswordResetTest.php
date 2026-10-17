<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Support\OneTimeCode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_reset_code_is_rejected(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email' => 'bad.reset@example.com',
        ]);

        PasswordResetRequest::query()->create([
            'user_id' => $user->id,
            'status' => PasswordResetRequest::STATUS_SENT,
            'requested_at' => now(),
            'handled_at' => now(),
            'reset_code_hash' => OneTimeCode::hash('654321'),
            'reset_code_expires_at' => now()->addHour(),
        ]);

        Livewire::test(RequestPasswordReset::class)
            ->set('step', 2)
            ->set('data', [
                'email' => 'bad.reset@example.com',
            ])
            ->fillForm([
                'code' => '000000',
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
            ])
            ->call('resetPassword')
            ->assertHasFormErrors(['code']);
    }
}
