<?php

namespace Tests\Feature;

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
