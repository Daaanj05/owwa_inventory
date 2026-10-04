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
        unset(
            $data['item_code'],
            $data['semi_expendable_property_number'],
            $data['ppe_property_number'],
            $data['item_category_id'],
        );

        $categoryId = (int) ($this->getRecord()?->item_category_id ?? 0);
        $data['base_name'] = Item::normalizeFamilyName((string) ($data['base_name'] ?? ''), $categoryId);
        $data['sub_item'] = filled($data['sub_item'] ?? null) ? trim((string) $data['sub_item']) : null;
        $data['name'] = Item::mergeDisplayName($data['base_name'], $data['sub_item']);

        $category = $this->getRecord()?->category;

        if ($category?->getTemplateSlug() === 'ppe' && blank($data['ppe_type'] ?? null) && filled($data['uacs_object_code_id'] ?? null)) {
            $ppeType = \App\Models\UacsObjectCode::query()
                ->whereKey($data['uacs_object_code_id'])
                ->value('property_class');

            if (filled($ppeType)) {
                $data['ppe_type'] = $ppeType;
            }
        }

        if ($category?->getTemplateSlug() === 'ppe') {
            $data['property_class'] = null;
            $data['inventory_type'] = null;
        }

        if ($category?->getTemplateSlug() === 'consumables') {
            $data['property_class'] = null;
            $data['ppe_type'] = null;
        }

        if ($category?->getTemplateSlug() === 'semi_expendable') {
            $data['inventory_type'] = null;
            $data['ppe_type'] = null;
        }

        return $data;
    }
}
