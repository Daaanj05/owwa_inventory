<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditItem extends EditRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (blank($data['base_name'] ?? null) && filled($data['name'] ?? null)) {
            $data['base_name'] = $data['name'];
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['base_name'] = trim((string) ($data['base_name'] ?? ''));
        $data['sub_item'] = filled($data['sub_item'] ?? null) ? trim((string) $data['sub_item']) : null;
        $data['name'] = Item::mergeDisplayName($data['base_name'], $data['sub_item']);

        return $data;
    }
}
