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
        $categoryId = (int) ($data['item_category_id'] ?? 0);
        $data['base_name'] = Item::normalizeFamilyName((string) ($data['base_name'] ?? ''), $categoryId);
        $data['sub_item'] = filled($data['sub_item'] ?? null) ? trim((string) $data['sub_item']) : null;
        $data['name'] = Item::mergeDisplayName($data['base_name'], $data['sub_item']);

        $category = $categoryId > 0
            ? \App\Models\ItemCategory::query()->find($categoryId)
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

        if ($category?->getTemplateSlug() !== 'consumables') {
            $data['reorder_level'] = 0;
        }

        return $data;
    }
}
