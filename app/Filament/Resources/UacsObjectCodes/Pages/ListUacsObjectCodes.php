<?php

namespace App\Filament\Resources\UacsObjectCodes\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\UacsObjectCodes\UacsObjectCodeResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\UacsObjectCode;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ListUacsObjectCodes extends ListRecords
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = UacsObjectCodeResource::class;

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
        $total = UacsObjectCode::query()->count();
        $active = UacsObjectCode::query()->active()->count();

        if ($total === 0) {
            return 'No UACS object codes yet.';
        }

        return "{$total} ".Str::plural('code', $total).", {$active} active. Archived codes stay available for history.";
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true))
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false))
                ->excludeQueryWhenResolvingRecord(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Flex::make([
                    $this->getTabsContentComponent(),
                    Actions::make([
                        OwwaFormModalDefaults::createActionForResource(
                            UacsObjectCodeResource::class,
                            OwwaFormModalDefaults::WIDTH_COMPACT,
                        )->mutateDataUsing(function (array $data): array {
                            $data['is_active'] = true;

                            return $data;
                        }),
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
