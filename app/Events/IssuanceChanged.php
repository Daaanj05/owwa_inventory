<?php

namespace App\Events;

use App\Models\Issuance;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssuanceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public Issuance $issuance,
        public string $action = 'created',
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->issuance->office_id) {
            $channels[] = new PrivateChannel('issuances.office.'.$this->issuance->office_id);
        }

        if ($this->issuance->issued_to) {
            $channels[] = new PrivateChannel('issuances.user.'.$this->issuance->issued_to);
        }

        $channels[] = new PrivateChannel('issuances.custodian');

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'issuance.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'issuance_id' => $this->issuance->id,
            'action' => $this->action,
        ];
    }
}
