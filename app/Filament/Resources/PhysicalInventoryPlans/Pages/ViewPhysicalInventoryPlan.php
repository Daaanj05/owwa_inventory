<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Pages;

use App\Filament\Concerns\RedirectsViewToTableModal;
use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPhysicalInventoryPlan extends ViewRecord
{
    use RedirectsViewToTableModal;

    protected static string $resource = PhysicalInventoryPlanResource::class;
}
