<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Schemas;

use App\Filament\Support\OwwaModalSchema;
use App\Support\PhysicalInventoryPlanViewPresenter;
use Filament\Schemas\Components\View as SchemaView;

class PhysicalInventoryPlanModalSchema
{
    /**
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function components(): array
    {
        return OwwaModalSchema::withHero(
            fn ($record): array => PhysicalInventoryPlanViewPresenter::forPlan($record),
            [
                ...PhysicalInventoryPlanInfolist::modalDetailSections(),
                SchemaView::make('filament.resources.physical-inventory-plans.partials.schedule-lines-table'),
            ],
        );
    }
}
