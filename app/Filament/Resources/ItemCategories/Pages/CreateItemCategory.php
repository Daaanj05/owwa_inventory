<?php

namespace App\Filament\Resources\ItemCategories\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\ItemCategories\ItemCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateItemCategory extends CreateRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = ItemCategoryResource::class;
}
