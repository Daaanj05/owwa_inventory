<?php

namespace App\Filament\Resources\UserLogs\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\UserLogs\UserLogResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListUserLogs extends ListRecords
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = UserLogResource::class;

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
        $days = (int) config('inventory.audit_log_archive_days', 30);

        return "Active shows the last {$days} days; Archived is older history (retained for audit).";
    }

    public function getTabs(): array
    {
        $days = (int) config('inventory.audit_log_archive_days', 30);
        $recent = now()->subDays($days);

        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNull('archived_at')
                    ->where('logged_in_at', '>=', $recent))
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where(function (Builder $query) use ($recent): void {
                        $query->whereNotNull('archived_at')
                            ->orWhere('logged_in_at', '<', $recent);
                    }))
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
                ])->alignStart(),
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
