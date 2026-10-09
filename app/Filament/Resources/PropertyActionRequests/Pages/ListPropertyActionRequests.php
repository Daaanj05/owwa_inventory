<?php

namespace App\Filament\Resources\PropertyActionRequests\Pages;

use App\Filament\Concerns\HasSearchRowToolbarActions;
use App\Filament\Concerns\ListensForPropertyActionBroadcasts;
use App\Filament\Concerns\SwitchesUcSentTab;
use App\Filament\Resources\PropertyActionRequests\Actions\PropertyActionRequestEmployeeActions;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Resources\PropertyActionRequests\Schemas\PropertyActionRequestForm;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\PropertyActionRequestCompileService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Livewire;

class ListPropertyActionRequests extends ListRecords
{
    use HasSearchRowToolbarActions;
    use ListensForPropertyActionBroadcasts;
    use SwitchesUcSentTab;

    protected static string $resource = PropertyActionRequestResource::class;

    #[Url(as: 'uc')]
    public ?string $ucTab = null;

    #[Url(as: 'uc_office')]
    public ?int $ucOfficeId = null;

    #[Url(as: 'uc_dept')]
    public ?int $ucDepartmentId = null;

    #[Url]
    public ?int $create = null;

    #[Url]
    public ?int $issuance_id = null;

    #[Url]
    public ?string $action_type = null;

    protected bool $ucToolbarHookRegistered = false;

    public function mount(): void
    {
        parent::mount();

        $this->initializeUcListScope();

        if ((int) ($this->create ?? 0) !== 1 || ! PropertyActionRequestResource::canCreate()) {
            return;
        }

        $issuanceId = (int) ($this->issuance_id ?? 0);
        $actionType = $this->action_type;

        $this->create = null;
        $this->issuance_id = null;
        $this->action_type = null;

        $this->mountAction('create', array_filter([
            'issuance_id' => $issuanceId > 0 ? $issuanceId : null,
            'action_type' => filled($actionType) ? $actionType : null,
        ]));
    }

    public function getTabs(): array
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user?->isEmployee()) {
            return [
                'active' => Tab::make('Active')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('archived_at'))
                    ->excludeQueryWhenResolvingRecord(),
                'archived' => Tab::make('Archived')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('archived_at'))
                    ->excludeQueryWhenResolvingRecord(),
            ];
        }

        if ($user?->isUnitConsolidator()) {
            return [
                'active' => Tab::make('Active')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('archived_at'))
                    ->excludeQueryWhenResolvingRecord(),
                'archived' => Tab::make('Archived')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('archived_at'))
                    ->excludeQueryWhenResolvingRecord(),
            ];
        }

        return [];
    }

    protected function getHeaderActions(): array
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        $createAction = OwwaFormModalDefaults::createActionForResource(PropertyActionRequestResource::class, OwwaFormModalDefaults::WIDTH_MEDIUM)
            ->modalHeading(fn (): string => Filament::auth()->user()?->isUnitConsolidator()
                ? 'Compile Property Returns To Supply Custodian'
                : 'Property Return')
            ->fillForm(function (array $arguments): array {
                $data = [
                    'action_type' => $arguments['action_type'] ?? PropertyActionRequest::ACTION_RETURN,
                    'lines' => [[
                        'issuance_id' => null,
                        'inventory_unit_id' => null,
                        'quantity' => 1,
                    ]],
                    'source_property_action_request_ids' => [],
                ];

                if (filled($arguments['action_type'] ?? null)) {
                    $data['action_type'] = $arguments['action_type'];
                }

                $sourceIds = array_values(array_filter(array_map(
                    'intval',
                    $arguments['prefillSourcePropertyActionRequestIds'] ?? [],
                )));

                if ($sourceIds !== []) {
                    $data['office_id'] = (int) ($arguments['office_id'] ?? 0) ?: null;
                    $data['department_id'] = (int) ($arguments['department_id'] ?? 0) ?: null;
                    $data['source_property_action_request_ids'] = $sourceIds;

                    $sources = PropertyActionRequest::query()->whereIn('id', $sourceIds)->get();
                    $actionTypes = $sources->pluck('action_type')->unique()->values();
                    $reasonCodes = $sources->pluck('reason_code')->unique()->values();

                    if ($actionTypes->count() === 1) {
                        $data['action_type'] = $actionTypes->first();
                    }

                    if ($reasonCodes->count() === 1) {
                        $data['reason_code'] = $reasonCodes->first() === 'good_condition'
                            ? 'needs_repair'
                            : $reasonCodes->first();
                    }

                    return $data;
                }

                $issuanceId = (int) ($arguments['issuance_id'] ?? 0);

                if ($issuanceId <= 0) {
                    return $data;
                }

                $issuance = Issuance::query()->with(['inventoryUnit', 'item'])->find($issuanceId);

                if (! $issuance) {
                    return $data;
                }

                return array_merge($data, [
                    'item_category_id' => $issuance->item?->item_category_id,
                    'lines' => [[
                        'issuance_id' => $issuance->id,
                        'inventory_unit_id' => $issuance->inventoryUnit?->id,
                        'quantity' => max(1, (int) ($issuance->quantity ?? 1)),
                    ]],
                ]);
            })
            ->mutateDataUsing(function (array $data): array {
                $user = Filament::auth()->user();

                if ($user instanceof User && $user->isUnitConsolidator()) {
                    unset($data['lines'], $data['item_category_id']);

                    return $data;
                }

                return PropertyActionRequestForm::hydrateParentFromLines(
                    $data,
                    $user instanceof User ? $user : null,
                );
            })
            ->using(function (array $data): PropertyActionRequest {
                $user = Filament::auth()->user();
                $sourceIds = array_values(array_filter(array_map(
                    'intval',
                    $data['source_property_action_request_ids'] ?? [],
                )));
                unset($data['source_property_action_request_ids']);

                if ($user instanceof User && $user->isUnitConsolidator()) {
                    if ($sourceIds === []) {
                        throw ValidationException::withMessages([
                            'source_property_action_request_ids' => 'Select at least one employee property return to compile.',
                        ]);
                    }

                    try {
                        return app(PropertyActionRequestCompileService::class)->createCompiledSubmission(
                            $user,
                            $sourceIds,
                            $data['reason_detail'] ?? null,
                            $data['action_type'] ?? null,
                            $data['reason_code'] ?? null,
                        );
                    } catch (\InvalidArgumentException $exception) {
                        throw ValidationException::withMessages([
                            'source_property_action_request_ids' => $exception->getMessage(),
                        ]);
                    }
                }

                $record = new PropertyActionRequest;
                $record->fill($data);
                $record->save();

                return $record;
            })
            ->after(function (PropertyActionRequest $record, Action $action): void {
                $user = Filament::auth()->user();

                if ($user instanceof User && $user->isUnitConsolidator()) {
                    $this->switchUcTabToSent();

                    return;
                }

                $workflow = $action->getArguments()['workflow'] ?? null;

                if ($workflow === PropertyActionRequestEmployeeActions::WORKFLOW_SUBMIT) {
                    try {
                        PropertyActionRequestEmployeeActions::submitRecord($record);
                    } catch (ValidationException $exception) {
                        $record->delete();

                        throw $exception;
                    }
                }
            })
            ->visible(fn (): bool => PropertyActionRequestResource::canCreate());

        if ($user?->isEmployee()) {
            $createAction
                ->modalSubmitActionLabel('Save draft')
                ->extraModalFooterActions(function (Action $createAction): array {
                    return PropertyActionRequestEmployeeActions::createModalFooterActions($createAction);
                });
        }

        if ($user?->isUnitConsolidator()) {
            $createAction
                ->modalSubmitActionLabel('Compile & send to SC')
                ->mountUsing(function (Action $action, ?Schema $schema): void {
                    $arguments = $action->getArguments();
                    $sourceIds = array_values(array_filter(array_map(
                        'intval',
                        $arguments['prefillSourcePropertyActionRequestIds'] ?? [],
                    )));

                    $officeId = (int) ($arguments['office_id'] ?? 0);
                    if ($officeId <= 0 && $this->ucOfficeId !== null && $this->ucOfficeId > 0) {
                        $officeId = $this->ucOfficeId;
                    }

                    $departmentId = (int) ($arguments['department_id'] ?? 0);
                    if ($departmentId <= 0 && $this->ucDepartmentId !== null && $this->ucDepartmentId > 0) {
                        $departmentId = $this->ucDepartmentId;
                    }

                    $fill = [
                        'office_id' => $officeId > 0 ? $officeId : null,
                        'department_id' => $departmentId > 0 ? $departmentId : null,
                        'action_type' => PropertyActionRequest::ACTION_RETURN,
                        'source_property_action_request_ids' => $sourceIds,
                    ];

                    if ($sourceIds !== []) {
                        $sources = PropertyActionRequest::query()->whereIn('id', $sourceIds)->get();
                        $actionTypes = $sources->pluck('action_type')->unique()->values();
                        $reasonCodes = $sources->pluck('reason_code')->unique()->values();

                        if ($actionTypes->count() === 1) {
                            $fill['action_type'] = $actionTypes->first();
                        }

                        if ($reasonCodes->count() === 1) {
                            $fill['reason_code'] = $reasonCodes->first() === 'good_condition'
                                ? 'needs_repair'
                                : $reasonCodes->first();
                        }
                    }

                    $schema?->fill($fill);
                });
        }

        return [
            $createAction,
        ];
    }

    public function cacheInteractsWithHeaderActions(): void
    {
        parent::cacheInteractsWithHeaderActions();

        $user = Filament::auth()->user();

        if ($user instanceof User && ($user->isUnitConsolidator() || $user->isEmployee())) {
            $this->cachedHeaderActions = [];
        }
    }

    public function getPageClasses(): array
    {
        $classes = array_merge(parent::getPageClasses(), ['owwa-tight-page']);

        /** @var User|null $user */
        $user = Filament::auth()->user();
        if ($user?->isUnitConsolidator()) {
            $classes[] = 'owwa-uc-requisitions-tabs';
        }

        if ($user?->isEmployee()) {
            $classes[] = 'owwa-search-row-toolbar';
            $classes[] = 'owwa-setup-archive-toggle';
        }

        return $classes;
    }

    public function content(Schema $schema): Schema
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user?->isUnitConsolidator()) {
            $this->ucTab ??= 'received';

            if (! $this->ucToolbarHookRegistered) {
                $this->ucToolbarHookRegistered = true;

                FilamentView::registerRenderHook(
                    TablesRenderHook::TOOLBAR_SEARCH_AFTER,
                    function (): HtmlString {
                        $livewire = Livewire::current();

                        if (! $livewire instanceof self) {
                            return new HtmlString('');
                        }

                        $user = Filament::auth()->user();

                        if (! $user?->isUnitConsolidator()) {
                            return new HtmlString('');
                        }

                        return new HtmlString(
                            (string) view('filament.tables.property-returns-uc-toolbar-secondary', [
                                'activeUcTab' => $livewire->ucTab ?? 'received',
                                'activeTab' => $livewire->activeTab,
                                'archivedCount' => $livewire->ucArchivedCount(),
                                'ucOfficeId' => $livewire->ucOfficeId,
                                'ucDepartmentId' => $livewire->ucDepartmentId,
                                'officeOptions' => $livewire->getUcOfficeOptions(),
                                'departmentOptions' => $livewire->getUcDepartmentOptions(),
                                'scopeComplete' => $livewire->ucListScopeIsComplete(),
                            ])
                        );
                    },
                    scopes: static::class,
                );
            }
        }

        if ($user?->isEmployee()) {
            $this->registerEmployeeActiveTabIcons();
        }

        if ($user?->isUnitConsolidator() || $user?->isEmployee()) {
            return $schema->components([
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
        }

        return parent::content($schema);
    }

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string}>
     */
    protected function searchRowToolbarButtons(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! PropertyActionRequestResource::canCreate()) {
            return [];
        }

        if ($user->isEmployee() || $user->isUnitConsolidator()) {
            return [
                [
                    'label' => 'New Property Return',
                    'action' => 'create',
                    'style' => 'primary',
                ],
            ];
        }

        return [];
    }

    protected function registerEmployeeActiveTabIcons(): void
    {
        static $hookRegistered = false;

        if ($hookRegistered) {
            return;
        }

        $hookRegistered = true;

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            function (): HtmlString {
                $livewire = Livewire::current();

                if (! $livewire instanceof self) {
                    return new HtmlString('');
                }

                $user = Filament::auth()->user();

                if (! $user?->isEmployee()) {
                    return new HtmlString('');
                }

                $activeTab = $livewire->activeTab ?? 'active';

                return new HtmlString(
                    (string) view('filament.tables.setup-active-tab-toggle', [
                        'showingArchived' => $activeTab === 'archived',
                        'archivedCount' => $livewire->employeeArchivedCount(),
                    ])
                );
            },
            scopes: static::class,
        );
    }

    public function employeeArchivedCount(): int
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->isEmployee()) {
            return 0;
        }

        return (int) PropertyActionRequestResource::getEloquentQuery()
            ->whereNotNull('archived_at')
            ->count();
    }

    public function ucArchivedCount(): int
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->isUnitConsolidator()) {
            return 0;
        }

        $query = PropertyActionRequestResource::getEloquentQuery()
            ->whereNotNull('archived_at');

        if (($this->ucTab ?? 'received') === 'sent') {
            return (int) $query->where(function (Builder $scope) use ($user): void {
                $scope
                    ->where('requested_by', $user->id)
                    ->orWhere(function (Builder $endorsed) use ($user): void {
                        $endorsed
                            ->where('uc_approved_by', $user->id)
                            ->where('status', '!=', PropertyActionRequest::STATUS_PENDING_UC)
                            ->whereHas(
                                'requestedBy',
                                fn (Builder $requester): Builder => $requester->where('role', User::ROLE_EMPLOYEE),
                            );
                    });
            })->count();
        }

        if (! $this->ucListScopeIsComplete()) {
            return 0;
        }

        return (int) $query
            ->where('status', PropertyActionRequest::STATUS_PENDING_UC)
            ->whereNull('compiled_into_property_action_request_id')
            ->whereHas('requestedBy', fn (Builder $q): Builder => $q->where('role', User::ROLE_EMPLOYEE))
            ->where('office_id', $this->ucOfficeId)
            ->where('department_id', $this->ucDepartmentId)
            ->count();
    }

    protected function getTableQuery(): Builder
    {
        $query = PropertyActionRequestResource::getEloquentQuery();

        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user?->isUnitConsolidator()) {
            $uc = $this->ucTab ?? 'received';

            if ($uc === 'sent') {
                return $query->where(function (Builder $scope) use ($user): void {
                    $scope
                        ->where('requested_by', $user->id)
                        ->orWhere(function (Builder $endorsed) use ($user): void {
                            $endorsed
                                ->where('uc_approved_by', $user->id)
                                ->where('status', '!=', PropertyActionRequest::STATUS_PENDING_UC)
                                ->whereHas(
                                    'requestedBy',
                                    fn (Builder $requester): Builder => $requester->where('role', User::ROLE_EMPLOYEE),
                                );
                        });
                });
            }

            $query = $query
                ->where('status', PropertyActionRequest::STATUS_PENDING_UC)
                ->whereNull('compiled_into_property_action_request_id')
                ->whereHas('requestedBy', fn (Builder $q): Builder => $q->where('role', User::ROLE_EMPLOYEE));

            if (! $this->ucListScopeIsComplete()) {
                return $query->whereRaw('1 = 0');
            }

            return $query
                ->where('office_id', $this->ucOfficeId)
                ->where('department_id', $this->ucDepartmentId);
        }

        return $query;
    }

    protected function initializeUcListScope(): void
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->isUnitConsolidator()) {
            return;
        }

        if ($user->hasSingleOfficeAssignment()) {
            $this->ucOfficeId ??= $user->assignedOfficeIds()[0] ?? null;
        }

        if ($this->ucOfficeId !== null
            && $this->ucOfficeId > 0
            && $user->hasSingleDepartmentAssignmentForOffice($this->ucOfficeId)) {
            $departmentIds = $user->assignedDepartmentIdsForOffice($this->ucOfficeId);
            $this->ucDepartmentId ??= $departmentIds[0] ?? null;
        }
    }

    public function updatedUcOfficeId(): void
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user instanceof User
            && $this->ucOfficeId !== null
            && $this->ucOfficeId > 0
            && $user->hasSingleDepartmentAssignmentForOffice($this->ucOfficeId)) {
            $departmentIds = $user->assignedDepartmentIdsForOffice($this->ucOfficeId);
            $this->ucDepartmentId = $departmentIds[0] ?? null;
        } else {
            $this->ucDepartmentId = null;
        }

        $this->resetTable();
    }

    public function updatedUcDepartmentId(): void
    {
        $this->resetTable();
    }

    public function updatedUcTab(): void
    {
        $this->resetTable();
    }

    public function ucListScopeIsComplete(): bool
    {
        return $this->ucOfficeId !== null
            && $this->ucOfficeId > 0
            && $this->ucDepartmentId !== null
            && $this->ucDepartmentId > 0;
    }

    /**
     * @return array<int, string>
     */
    public function getUcOfficeOptions(): array
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $officeIds = $user->assignedOfficeIds();

        if ($officeIds === []) {
            return [];
        }

        return Office::query()
            ->active()
            ->whereIn('id', $officeIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function getUcDepartmentOptions(): array
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if (! $user instanceof User || $this->ucOfficeId === null || $this->ucOfficeId <= 0) {
            return [];
        }

        $departmentIds = $user->assignedDepartmentIdsForOffice($this->ucOfficeId);

        if ($departmentIds === []) {
            return [];
        }

        return Department::query()
            ->active()
            ->where('office_id', $this->ucOfficeId)
            ->whereIn('id', $departmentIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
