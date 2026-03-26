<?php

namespace App\Filament\Resources\ItemCategories\Pages;

use App\Filament\Resources\ItemCategories\ItemCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListItemCategories extends ListRecords
{
    protected static string $resource = ItemCategoryResource::class;

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $total = \App\Models\ItemCategory::count();
        return $total > 0
            ? "{$total} " . \Illuminate\Support\Str::plural('category', $total) . ' defined.'
            : 'No categories yet. Create categories before adding items.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
