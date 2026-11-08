<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\LoginEmailAuthenticationNotification;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginEmailAuthenticationNotificationTest extends TestCase
{
    #[DataProvider('panelIds')]
    public function test_login_email_code_uses_the_mail_queue(string $panelId): void
    {
        $provider = Filament::getPanel($panelId)->getMultiFactorAuthenticationProviders()['email_code'] ?? null;

        $this->assertInstanceOf(EmailAuthentication::class, $provider);
        $this->assertSame(LoginEmailAuthenticationNotification::class, $provider->getCodeNotification());

        $notification = new LoginEmailAuthenticationNotification('123456', 4);

        $this->assertSame('mail', $notification->queue);

        $user = new User([
            'name' => 'Satornina Mendiola Rizal',
            'email' => 'gamingacc4884@gmail.com',
        ]);
        $html = (string) $notification->toMail($user)->render();

        $this->assertStringContainsString('Your sign-in code', $html);
        $this->assertStringContainsString('font-size: 36px', $html);
        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('This code expires in 4 minutes.', $html);
        $this->assertStringNotContainsString('Your sign-in code is: 123456', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function panelIds(): array
    {
        return [
            'admin' => ['admin'],
            'system admin' => ['system-admin'],
        ];
    }
}
