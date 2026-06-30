<?php

namespace Tests\Unit;

use App\Support\MailDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MailDeliveryTest extends TestCase
{
    public function test_attempt_returns_true_when_send_succeeds(): void
    {
        $this->assertTrue(MailDelivery::attempt(fn (): string => 'sent'));
    }

    public function test_attempt_returns_false_on_transport_exception(): void
    {
        $this->assertFalse(MailDelivery::attempt(function (): void {
            throw new TransportException('Connection could not be established with host "smtp.gmail.com:587"');
        }));
    }

    public function test_attempt_rethrows_non_mail_exceptions(): void
    {
        $this->expectException(\RuntimeException::class);

        MailDelivery::attempt(function (): void {
            throw new \RuntimeException('Database error');
        });
    }

    #[DataProvider('mailTransportFailureMessages')]
    public function test_detects_mail_transport_failures_from_message(string $message): void
    {
        $this->assertTrue(MailDelivery::isMailTransportFailure(new \Exception($message)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mailTransportFailureMessages(): array
    {
        return [
            'connection timed out' => ['stream_socket_client(): Unable to connect to smtp.gmail.com:587 (Connection timed out)'],
            'unable to connect' => ['Unable to connect to mail server'],
            'could not be established' => ['Connection could not be established with host "smtp.example.com:587"'],
        ];
    }
}
