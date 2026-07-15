<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Resources\Pages\CreateRecord;

class CreateItem extends CreateRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = ItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['base_name'] = trim((string) ($data['base_name'] ?? ''));
        $data['sub_item'] = filled($data['sub_item'] ?? null) ? trim((string) $data['sub_item']) : null;
        $data['name'] = Item::mergeDisplayName($data['base_name'], $data['sub_item']);

        return $data;
    }
}
