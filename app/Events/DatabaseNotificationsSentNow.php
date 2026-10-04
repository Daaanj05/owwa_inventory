<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DatabaseNotificationsSentNow implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if (method_exists($this->user, 'receivesBroadcastNotificationsOn')) {
            return [new PrivateChannel($this->user->receivesBroadcastNotificationsOn())];
        }

        $userClass = str_replace('\\', '.', $this->user::class);

        return [new PrivateChannel("{$userClass}.{$this->user->getKey()}")];
    }

    public function broadcastAs(): string
    {
        return 'database-notifications.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
