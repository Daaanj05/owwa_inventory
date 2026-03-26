<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Models\ItemCategory;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class ListAcquisitions extends ListRecords
{
    protected static string $resource = AcquisitionResource::class;

    public ?int $activeItemCategoryId = null;

    /**
     * Filament schemas sometimes call `getRecord()` even on "list" pages (e.g. when
     * evaluating field visibility/hidden state). List pages don't have a selected
     * record, so we return `null`.
     */
    public function getRecord(): mixed
    {
        return null;
    }

    public function mount(): void
    {
        // Register the loading overlay via a render hook so it is part of the
        // *compiled* Livewire template. Html::make() does NOT go through Blade/
        // Livewire compilation, meaning wire:loading inside it is dead HTML.
        // A render hook is emitted directly into the page's Blade template and
        // therefore gets compiled by Livewire correctly.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            fn(): \Illuminate\Contracts\View\View => view(
                'filament.acquisitions.loading-overlay'
            ),
            scopes: static::class,
        );

        // Clear legacy query-string filter params from older implementations.
        if (request()->has('filters')) {
            $this->redirect(request()->url());
        }

        // AIMS-style behavior: each time user opens the Acquisitions module,
        // start with "Select category" (no preselected category).
        session()->forget('active_item_category_id');
        $this->activeItemCategoryId = null;
    }

    public function setActiveItemCategoryId(?string $value): void
    {
        $id = filled($value) ? (int) $value : null;

        $this->activeItemCategoryId = $id;

        if (filled($id)) {
            session()->put('active_item_category_id', $id);
        } else {
            session()->forget('active_item_category_id');
        }

        if (request()->has('filters')) {
            $this->redirect(request()->url());
        }
    }

    public function updatedActiveItemCategoryId(?int $value): void
    {
        if (filled($value)) {
            session()->put('active_item_category_id', (int) $value);
        } else {
            session()->forget('active_item_category_id');
        }

        if (request()->has('filters')) {
            $this->redirect(request()->url());
        }
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->withoutTrashed())
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->onlyTrashed())
                ->excludeQueryWhenResolvingRecord(),
            'all' => Tab::make('All')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->withoutGlobalScopes([SoftDeletingScope::class]))
                ->excludeQueryWhenResolvingRecord(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $categories = cache()->remember(
            'item_categories.options',
            3600,
            fn(): array => ItemCategory::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray()
        );

        $selectedId = $this->activeItemCategoryId;

        $optionsHtml = '';
        foreach ($categories as $id => $name) {
            $selectedAttr = ((string) $id === (string) $selectedId) ? ' selected' : '';
            $optionsHtml .= "<option value=\"{$id}\"{$selectedAttr}>" . e($name) . "</option>";
        }

        $placeholderOptionHtml = blank($selectedId)
            ? '<option value="" style="color:#6b7280">Select category</option>'
            : '<option value="" disabled style="color:#6b7280">Select category</option>';

        $selectTextClass = blank($selectedId) ? 'text-gray-500' : 'text-gray-900';
        $selectTextStyle = blank($selectedId) ? 'style="color:#6b7280"' : 'style="color:#111827"';

        $categorySelectHtml = '
            <div class="fi-ta-filter-block w-full max-w-xs mb-3">
                <select id="active_item_category_id"
                        class="fi-input fi-select ' . $selectTextClass . '"
                        ' . $selectTextStyle . '
                        wire:model.live="activeItemCategoryId">
                    ' . $placeholderOptionHtml . '
                    ' . $optionsHtml . '
                </select>
            </div>
        ';

        if (blank($selectedId)) {
            return $schema->components([
                Html::make($categorySelectHtml),
                Html::make('
                    <div class="min-h-[14rem] flex flex-col justify-start">
                        <div class="fi-ta-subtle mt-4">
                            Select a category to view acquisitions.
                        </div>
                    </div>
                '),
            ]);
        }

        return $schema->components([
            Html::make($categorySelectHtml),
            Flex::make([
                $this->getTabsContentComponent(),
                Actions::make([
                    CreateAction::make()
                        ->modalWidth('5xl'),
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