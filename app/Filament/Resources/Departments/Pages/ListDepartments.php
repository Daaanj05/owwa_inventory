<?php

namespace App\Filament\Resources\Departments\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Department;
use App\Services\DepartmentBulkCreateService;
use Filament\Notifications\Notification;
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

class ListDepartments extends ListRecords
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = DepartmentResource::class;

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
        return null;
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
        ];
    }

    public function content(Schema $schema): Schema
    {
        $createAction = OwwaFormModalDefaults::createActionForResource(
            DepartmentResource::class,
            OwwaFormModalDefaults::WIDTH_COMPACT,
            'Select an office, then add one row per sub-office or department.',
        )
            ->using(function (array $data): Department {
                $created = app(DepartmentBulkCreateService::class)->createForOffice(
                    (int) $data['office_id'],
                    $data['lines'] ?? [],
                );

                return $created->first();
            })
            ->successNotification(function (Department $record, array $data): Notification {
                $count = count(app(DepartmentBulkCreateService::class)->normalizeLines($data['lines'] ?? []));
                $label = Str::plural('sub-office/department', $count);

                return Notification::make()
                    ->title('Created')
                    ->body("{$count} {$label} created.")
                    ->success();
            });

        return $schema
            ->components([
                Flex::make([
                    $this->getTabsContentComponent(),
                    Actions::make([
                        $createAction,
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
