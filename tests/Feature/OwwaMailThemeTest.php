<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\UserWelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OwwaMailThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_email_renders_owwa_branding_and_verification_url(): void
    {
        URL::forceRootUrl('https://capstoneproject.test');

        $user = User::factory()->make([
            'name' => 'Jane Employee',
            'email' => 'jane@example.com',
        ]);

        $verificationUrl = 'https://capstoneproject.test/admin/email-verification/verify/1/abc123';
        $loginUrl = 'https://capstoneproject.test/admin/login';

        $notification = new UserWelcomeNotification(
            temporaryPassword: 'TempPass1!',
            panelLoginUrl: $loginUrl,
            verificationUrl: $verificationUrl,
        );

        $html = (string) $notification->toMail($user)->render();

        $this->assertStringContainsString(config('owwa_mail.brand_name'), $html);
        $this->assertStringContainsString(config('owwa_mail.tagline'), $html);
        $this->assertStringContainsString(URL::to(config('owwa_mail.logos.owwa')), $html);
        $this->assertStringContainsString(URL::to(config('owwa_mail.logos.bagong_pilipinas')), $html);
        $this->assertStringContainsString($verificationUrl, $html);
        $this->assertStringContainsString('TempPass1!', $html);
        $this->assertStringContainsString('Verify email address', $html);
        $this->assertStringContainsString('#003f8a', $html);
    }
}
