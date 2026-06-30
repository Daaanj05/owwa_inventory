<?php

namespace App\Filament\Resources\PhysicalCountSessions\Concerns;

use App\Filament\Resources\PhysicalCountSessions\Actions\PhysicalCountSessionActions;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Models\PhysicalCountSession;

trait HasPhysicalCountQrActions
{
    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function physicalCountQrHeaderActions(): array
    {
        return [
            PhysicalCountSessionActions::scanWithPhoneAction(),
            PhysicalCountSessionActions::preloadExpectedAssetsAction(
                fn (PhysicalCountSession $record): mixed => $this->redirect(
                    PhysicalCountSessionResource::viewModalUrl($record),
                ),
            ),
            PhysicalCountSessionActions::printQrLabelsAction(),
        ];
    }
}
