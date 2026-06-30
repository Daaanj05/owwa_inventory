<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Pages;

use App\Filament\Concerns\RedirectsCreateToList;
use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePhysicalInventoryPlan extends CreateRecord
{
    use RedirectsCreateToList;

    protected static string $resource = PhysicalInventoryPlanResource::class;
}
