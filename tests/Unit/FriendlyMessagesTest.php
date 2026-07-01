<?php

namespace Tests\Unit;

use App\Support\FriendlyMessages;
use Tests\TestCase;

class FriendlyMessagesTest extends TestCase
{
    public function test_welcome_email_messages_include_email_and_password(): void
    {
        $this->assertStringContainsString('test@example.com', FriendlyMessages::welcomeEmailSent('test@example.com', 'Pass123!'));
        $this->assertStringContainsString('Pass123!', FriendlyMessages::welcomeEmailQueued('test@example.com', 'Pass123!'));
        $this->assertStringContainsString('mail worker', FriendlyMessages::welcomeEmailQueued('test@example.com', 'Pass123!'));
    }

    public function test_downtime_messages_are_non_empty(): void
    {
        $this->assertNotSame('', FriendlyMessages::websiteUnavailable503());
        $this->assertNotSame('', FriendlyMessages::websiteError500());
        $this->assertNotSame('', FriendlyMessages::verificationResendFailed());
    }
}
