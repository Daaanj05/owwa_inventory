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

        $category = filled($data['item_category_id'] ?? null)
            ? \App\Models\ItemCategory::query()->find($data['item_category_id'])
            : null;

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
