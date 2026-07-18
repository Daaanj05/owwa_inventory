<?php

namespace App\Filament\Resources\Acquisitions\Paperwork\Schemas;

class AcquisitionPaperworkModalSchema
{
    /**
     * PR list view — purchase request details only (no workflow stepper).
     *
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function components(): array
    {
        return AcquisitionPaperworkInfolist::prViewSections();
    }

    /**
     * Received list view — header refs + received line items table only.
     *
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function receivedComponents(): array
    {
        return AcquisitionPaperworkInfolist::receivedViewSections();
    }
}
