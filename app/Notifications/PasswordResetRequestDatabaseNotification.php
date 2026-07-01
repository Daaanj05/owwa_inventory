<?php

namespace App\Notifications;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Notifications\Concerns\InteractsWithFilamentDatabase;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PasswordResetRequestDatabaseNotification extends Notification
{
    use InteractsWithFilamentDatabase;
    use Queueable;

    public function __construct(
        public string $userName,
        public string $userEmail,
        public User $requestingUser,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->filamentDatabaseMessage(
            'Password reset requested',
            sprintf('%s (%s) requested a password reset from the admin login page.', $this->userName, $this->userEmail),
            UserResource::viewModalUrl($this->requestingUser),
            'Review user',
            Heroicon::OutlinedKey,
            'warning',
        );
    }
}
