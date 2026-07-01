<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\UserWelcomeNotification;
use App\Support\MailDelivery;
use App\Support\MailDeliveryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_returns_true_when_send_succeeds(): void
    {
        $this->assertTrue(MailDelivery::attempt(fn (): string => 'sent'));
    }

    public function test_notify_reports_queued_when_using_database_queue(): void
    {
        config(['queue.default' => 'database']);

        NotificationFacade::fake();

        $user = User::factory()->create();

        $result = MailDelivery::notify($user, new UserWelcomeNotification(
            'TempPass1!',
            'https://example.com/login',
            'https://example.com/verify',
        ));

        $this->assertInstanceOf(MailDeliveryResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertTrue($result->wasQueued);

        NotificationFacade::assertSentTo($user, UserWelcomeNotification::class);
    }

    public function test_notify_reports_sent_when_queue_is_sync(): void
    {
        config(['queue.default' => 'sync']);

        NotificationFacade::fake();

        $user = User::factory()->create();

        $result = MailDelivery::notify($user, new UserWelcomeNotification(
            'TempPass1!',
            'https://example.com/login',
            'https://example.com/verify',
        ));

        $this->assertTrue($result->success);
        $this->assertFalse($result->wasQueued);
    }

    public function test_notify_treats_non_queued_notifications_as_sent(): void
    {
        config(['queue.default' => 'database']);

        NotificationFacade::fake();

        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }
        };

        $user = User::factory()->create();

        $result = MailDelivery::notify($user, $notification);

        $this->assertTrue($result->success);
        $this->assertFalse($result->wasQueued);
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
