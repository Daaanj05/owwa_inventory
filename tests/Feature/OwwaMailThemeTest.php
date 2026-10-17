<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Support\OwwaMailLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class OwwaMailThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_email_renders_owwa_branding_and_code(): void
    {
        URL::forceRootUrl('https://capstoneproject.test');

        $user = User::factory()->make([
            'name' => 'Jane Employee',
            'email' => 'jane@example.com',
        ]);

        $notification = new EmailVerificationCodeNotification(
            code: '123456',
        );

        $html = (string) $notification->toMail($user)->render();

        $this->assertStringContainsString(config('owwa_mail.brand_name'), $html);
        $this->assertStringContainsString(config('owwa_mail.tagline'), $html);
        $this->assertStringContainsString('data:image/', $html);
        $this->assertStringNotContainsString(URL::to(config('owwa_mail.logos.owwa')), $html);
        $this->assertStringNotContainsString(URL::to(config('owwa_mail.logos.bagong_pilipinas')), $html);
        $this->assertStringContainsString('123456', $html);
        $this->assertStringNotContainsString('Temporary password', $html);
        $this->assertStringContainsString('Verification code', $html);
        $this->assertStringContainsString('#003f8a', $html);
    }

    public function test_sent_mail_embeds_logos_instead_of_remote_urls(): void
    {
        $message = new Message(new Email);
        $src = OwwaMailLogo::src($message, 'owwa');
        $part = $message->getSymfonyMessage()->getAttachments()[0];

        $this->assertSame('cid:owwa@owwa.mail', $src);
        $this->assertSame('inline', $part->getDisposition());
        $this->assertNull($part->getFilename());
    }
}
