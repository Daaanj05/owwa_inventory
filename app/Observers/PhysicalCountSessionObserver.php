<?php

namespace App\Observers;

use App\Models\PhysicalCountSession;
use App\Services\InventoryPlanLineStatusService;

class PhysicalCountSessionObserver
{
    public function updated(PhysicalCountSession $session): void
    {
        if (! $session->wasChanged('status') || ! $session->isComplete()) {
            return;
        }

        app(InventoryPlanLineStatusService::class)->syncForSession($session);
    }
}
