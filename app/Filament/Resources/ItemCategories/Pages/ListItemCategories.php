<?php

namespace App\Filament\Resources\ItemCategories\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\ItemCategories\ItemCategoryResource;
use App\Filament\Support\OwwaFormModalDefaults;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListItemCategories extends ListRecords
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = ItemCategoryResource::class;

    /**
     * Filament schemas sometimes call `getRecord()` even on "list" pages.
     * List pages don't have a selected record, so we return `null`.
     */
    public function getRecord(): mixed
    {
        return null;
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $total = \App\Models\ItemCategory::query()->whereNull('archived_at')->count();

        return $total > 0
            ? "{$total} active ".\Illuminate\Support\Str::plural('category', $total).' defined.'
            : 'No active categories yet. Create categories before adding items.';
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('archived_at'))
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('archived_at'))
                ->excludeQueryWhenResolvingRecord(),
            'all' => Tab::make('All'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Flex::make([
                    $this->getTabsContentComponent(),
                    Actions::make([
                        OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_COMPACT),
                    ])->alignEnd(),
                ])->alignBetween()->verticallyAlignCenter(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
