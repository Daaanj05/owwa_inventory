<?php

namespace App\Events;

use App\Models\Requisition;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RequisitionChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public Requisition $requisition,
        public string $action = 'updated',
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->requisition->office_id) {
            $channels[] = new PrivateChannel('requisitions.office.'.$this->requisition->office_id);
        }

        $this->requisition->loadMissing('requestedBy');

        if ($this->requisition->requestedBy?->isUnitConsolidator()) {
            $channels[] = new PrivateChannel('requisitions.custodian');
        }

        if ($this->requisition->requested_by) {
            $channels[] = new PrivateChannel('requisitions.user.'.$this->requisition->requested_by);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'requisition.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->requisition->id,
            'status' => $this->requisition->status,
            'office_id' => $this->requisition->office_id,
            'requested_by' => $this->requisition->requested_by,
            'reference_code' => $this->requisition->reference_code,
            'action' => $this->action,
        ];
    }
}
