<?php

namespace App\Filament\Concerns;

trait HasRequisitionRefreshFallback
{
    public function requisitionRefreshPollingInterval(): ?string
    {
        if (filled(config('filament.broadcasting.echo.key'))) {
            return null;
        }

        $interval = config('inventory.requisition_poll_interval');

        return filled($interval) ? (string) $interval : null;
    }

    public function getPollingInterval(): ?string
    {
        return $this->requisitionRefreshPollingInterval();
    }
}
