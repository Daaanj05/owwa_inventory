<?php

namespace App\Filament\Concerns;

trait HasPropertyActionRefreshFallback
{
    public function propertyActionRefreshPollingInterval(): ?string
    {
        $interval = config('inventory.requisition_poll_interval');

        return filled($interval) ? (string) $interval : null;
    }

    public function getPollingInterval(): ?string
    {
        return $this->propertyActionRefreshPollingInterval();
    }
}
