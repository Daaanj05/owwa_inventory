<?php

namespace App\Filament\Resources\Requisitions\Pages;

use App\Filament\Concerns\CoaListPageExports;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\ListensForRequisitionBroadcasts;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Filament\Resources\Requisitions\Schemas\RequisitionForm;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Requisition;
use App\Models\User;
use App\Services\RequisitionCompileService;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ListRequisitions extends ListRecords
{
    use CoaListPageExports;
    use HasSystemAdminWizardHeading;
    use ListensForRequisitionBroadcasts;

    protected static string $resource = RequisitionResource::class;

    #[Url(as: 'uc')]
    public ?string $ucTab = null;

    #[Url]
    public ?int $create = null;

    #[Url]
    public ?int $item_id = null;

    #[Url]
    public ?int $category = null;

    public function mount(): void
    {
        parent::mount();

        if ((int) ($this->create ?? 0) !== 1 || ! RequisitionResource::canCreate()) {
            return;
        }

        $itemId = (int) ($this->item_id ?? 0);
        $categoryId = $this->category;

        $this->create = null;
        $this->item_id = null;
        $this->category = null;

        $this->mountAction('create', array_filter([
            'catalogPrefillItemId' => $itemId > 0 ? $itemId : null,
            'catalogPrefillCategoryId' => filled($categoryId) ? (int) $categoryId : null,
        ]), ['schemaComponent' => 'content']);
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        $classes = array_merge(parent::getPageClasses(), ['owwa-tight-page']);

        /** @var User|null $user */
        $user = Filament::auth()->user();
        if ($user?->isUnitConsolidator()) {
            $classes[] = 'owwa-uc-requisitions-tabs';
        }

        return $classes;
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $pending = RequisitionResource::getEloquentQuery()
            ->where('status', Requisition::STATUS_PENDING)
            ->count();

        return $pending > 0
            ? "{$pending} pending ".\Illuminate\Support\Str::plural('requisition', $pending).' awaiting action.'
            : 'All requisitions are up to date.';
    }

    public function getTabs(): array
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user?->isUnitConsolidator()) {
            return [
                'active' => Tab::make('Active')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                        Requisition::STATUS_PENDING,
                        Requisition::STATUS_ACCEPTED,
                    ]))
                    ->excludeQueryWhenResolvingRecord(),
                'archived' => Tab::make('Archived')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Requisition::STATUS_REJECTED))
                    ->excludeQueryWhenResolvingRecord(),
                'all' => Tab::make('All')
                    ->excludeQueryWhenResolvingRecord(),
            ];
        }

        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    Requisition::STATUS_PENDING,
                    Requisition::STATUS_ACCEPTED,
                ]))
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Requisition::STATUS_REJECTED))
                ->excludeQueryWhenResolvingRecord(),
            'all' => Tab::make('All')
                ->excludeQueryWhenResolvingRecord(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user?->isUnitConsolidator()) {
            $this->ucTab ??= 'received';

            static $hookRegistered = false;

            if (! $hookRegistered) {
                $hookRegistered = true;

                FilamentView::registerRenderHook(
                    TablesRenderHook::TOOLBAR_SEARCH_AFTER,
                    fn (): HtmlString => new HtmlString(
                        (string) view('filament.tables.requisitions-secondary-tabs', [
                            'activeUcTab' => $this->ucTab ?? 'received',
                        ])
                    ),
                    scopes: static::class,
                );
            }
        }

        $actionsComponent = Actions::make([
            $this->coaExportReportAction(
                'coaRequisition',
                'owwa.export.bulk.requisitions',
                'Export RIS — selected rows',
            ),
            OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_WIDE)
                ->mountUsing(function (Action $action, ?Schema $schema): void {
                    $itemId = (int) ($action->getArguments()['catalogPrefillItemId'] ?? 0);

                    if ($itemId > 0) {
                        $categoryId = $action->getArguments()['catalogPrefillCategoryId'] ?? null;

                        $prefill = RequisitionForm::catalogPrefillState(
                            $itemId,
                            filled($categoryId) ? (int) $categoryId : null,
                        );

                        if ($prefill !== []) {
                            $schema?->fill($prefill);

                            return;
                        }
                    }

                    $schema?->fill();
                })
                ->modalHeading(fn (): string => Filament::auth()->user()?->isUnitConsolidator()
                    ? 'New requisition to Supply Custodian'
                    : 'New requisition')
                ->using(function (array $data, HasActions&HasSchemas $livewire): Model {
                    /** @var class-string<Model> $model */
                    $model = static::getResource()::getModel();

                    $sourceIds = array_values(array_filter($data['source_requisition_ids'] ?? []));
                    unset($data['source_requisition_ids']);

                    $record = new $model;
                    $record->fill(Arr::except($data, ['items']));
                    $record->save();

                    if ($sourceIds !== []) {
                        /** @var User|null $user */
                        $user = Filament::auth()->user();

                        if ($user instanceof User) {
                            try {
                                app(RequisitionCompileService::class)->linkCompiledSources(
                                    $user,
                                    $record,
                                    $sourceIds,
                                );
                            } catch (\InvalidArgumentException $exception) {
                                $record->delete();

                                throw ValidationException::withMessages([
                                    'source_requisition_ids' => $exception->getMessage(),
                                ]);
                            }
                        }
                    }

                    return $record;
                })
                ->mutateFormDataUsing(function (array $data): array {
                    /** @var User|null $user */
                    $user = Filament::auth()->user();

                    if (! $user) {
                        return $data;
                    }

                    $data['requested_by'] = $user->id;

                    if (blank($user->office_id)) {
                        throw ValidationException::withMessages([
                            'office_id' => 'Your account has no Office assigned. Please contact the System Admin.',
                        ]);
                    }

                    $data['office_id'] = (int) $user->office_id;
                    $data['department_id'] = $user->department_id ? (int) $user->department_id : null;

                    if ($user->isEmployee()) {
                        $data['remarks'] = null;
                        $data['purpose'] = null;
                    }

                    return $data;
                })
                ->visible(fn (): bool => RequisitionResource::canCreate()),
        ]);

        /** @var mixed $actionsComponent */
        $actionsComponent = $actionsComponent->alignEnd();

        $flexComponent = Flex::make([
            $this->getTabsContentComponent(),
            $actionsComponent,
        ]);

        /** @var mixed $flexComponent */
        $flexComponent = $flexComponent->alignBetween()->verticallyAlignCenter();

        return $schema->components([
            $flexComponent,
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    protected function getTableQuery(): Builder
    {
        $query = RequisitionResource::getEloquentQuery();

        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user?->isUnitConsolidator()) {
            $uc = $this->ucTab ?? 'received';

            if ($uc === 'sent') {
                return $query->where('requested_by', $user->id);
            }

            return $query->whereHas('requestedBy', fn (Builder $q): Builder => $q->where('role', User::ROLE_EMPLOYEE));
        }

        return $query;
    }

    public function refreshFromRequisitionBroadcast(): void
    {
        $this->resetTable();
    }
}
