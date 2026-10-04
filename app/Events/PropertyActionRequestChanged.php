<?php

namespace App\Events;

use App\Models\PropertyActionRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PropertyActionRequestChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PropertyActionRequest $propertyActionRequest,
        public string $action = 'updated',
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->propertyActionRequest->office_id) {
            $channels[] = new PrivateChannel('property-actions.office.'.$this->propertyActionRequest->office_id);
        }

        $this->propertyActionRequest->loadMissing('requestedBy');

        $status = $this->propertyActionRequest->status;
        $custodianStatuses = [
            PropertyActionRequest::STATUS_PENDING_SC,
            PropertyActionRequest::STATUS_APPROVED,
            PropertyActionRequest::STATUS_REJECTED,
            PropertyActionRequest::STATUS_EXECUTED,
        ];

        if (in_array($status, $custodianStatuses, true)
            || $this->propertyActionRequest->requestedBy?->isUnitConsolidator()) {
            $channels[] = new PrivateChannel('property-actions.custodian');
        }

        if ($this->propertyActionRequest->requested_by) {
            $channels[] = new PrivateChannel('property-actions.user.'.$this->propertyActionRequest->requested_by);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'property-action.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->propertyActionRequest->id,
            'status' => $this->propertyActionRequest->status,
            'office_id' => $this->propertyActionRequest->office_id,
            'requested_by' => $this->propertyActionRequest->requested_by,
            'reference_code' => $this->propertyActionRequest->reference_code,
            'action' => $this->action,
        ];
    }
}
