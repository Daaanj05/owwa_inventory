<?php

namespace Tests\Feature;

use App\Listeners\PrepareInlineMailLogos;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Support\OwwaMailLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Event;
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
        $this->assertStringContainsString('This code expires in 24 hours.', $html);
        $this->assertStringContainsString('#003f8a', $html);
    }

    public function test_sign_in_mail_embeds_logos_for_gmail(): void
    {
        $message = new Message(new Email);
        $markdown = app(Markdown::class);
        $data = [
            'message' => $message,
            'level' => 'primary',
            'greeting' => 'Hello!',
            'introLines' => ['Your sign-in code is 414441'],
            'outroLines' => ['This code will expire in 4 minutes.'],
            'salutation' => 'Regards',
        ];

        $html = (string) $markdown->render('notifications::email', $data);
        $text = (string) $markdown->renderText('notifications::email', $data);

        $this->assertStringContainsString('cid:', $html);
        $this->assertMatchesRegularExpression('/cid:[^"\']+@[^"\']+/', $html);
        $this->assertStringNotContainsString('data:image/', $html);
        $this->assertStringContainsString('Your sign-in code is 414441', $text);
        $this->assertCount(2, $message->getSymfonyMessage()->getAttachments());
    }

    public function test_sent_mail_embeds_logos_instead_of_remote_urls(): void
    {
        $message = new Message(new Email);
        $src = OwwaMailLogo::src($message, 'owwa');
        $part = $message->getSymfonyMessage()->getAttachments()[0];

        $this->assertStringStartsWith('cid:', $src);
        $this->assertSame('inline', $part->getDisposition());
        $this->assertSame('owwa-form-logo.png', $part->getFilename());
    }

    public function test_gmail_mime_inlines_logos_without_a_filename_or_a_split_cid(): void
    {
        $this->assertTrue(Event::hasListeners(MessageSending::class));

        $message = new Message(new Email);
        $markdown = app(Markdown::class);
        $data = [
            'message' => $message,
            'level' => 'primary',
            'greeting' => 'Hello!',
            'introLines' => ['Your sign-in code is 414441'],
            'outroLines' => ['This code will expire in 4 minutes.'],
            'salutation' => 'Regards',
        ];

        $html = (string) $markdown->render('notifications::email', $data);
        $text = (string) $markdown->renderText('notifications::email', $data);
        $email = $message->getSymfonyMessage();
        $email->from('inventory@example.com');
        $email->to('staff@example.com');
        $email->subject('Sign-in code');
        $email->html($html);
        $email->text($text);

        (new PrepareInlineMailLogos)->handle(new MessageSending($email, []));

        $raw = $email->toString();

        $this->assertMatchesRegularExpression(
            '/Content-Type: multipart\/alternative;.*?Content-Type: multipart\/related;.*?Content-Type: text\/html;.*?Content-Type: image\/png/s',
            $raw,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Content-Type: multipart\/related;.*?Content-Type: multipart\/alternative;/s',
            $raw,
        );

        preg_match_all(
            '/Content-ID: <([^>\r\n]+)>\r\nContent-Type: image\/png\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: inline\r\n\r\n/',
            $raw,
            $images,
        );
        $this->assertCount(2, $images[1]);

        preg_match('/Content-Type: text\/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.+?)\r\n--/s', $raw, $htmlPart);
        $decoded = base64_decode(preg_replace('/\s+/', '', $htmlPart[1] ?? ''), true);

        $this->assertIsString($decoded);
        preg_match_all('/src="cid:([^"]+)"/', $decoded, $cids);
        $this->assertEqualsCanonicalizing($images[1], $cids[1]);
        $this->assertStringNotContainsString("\n", implode('', $cids[1]));
    }
}
