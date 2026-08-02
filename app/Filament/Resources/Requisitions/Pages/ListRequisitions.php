<?php

namespace App\Filament\Resources\Requisitions\Pages;

use App\Filament\Concerns\CoaListPageExports;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\ListensForRequisitionBroadcasts;
use App\Filament\Concerns\SwitchesUcSentTab;
use App\Filament\Resources\Requisitions\Actions\EmployeeRequisitionActions;
use App\Filament\Resources\Requisitions\Actions\RequisitionExportActions;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Filament\Resources\Requisitions\Schemas\RequisitionForm;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Department;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionCompileService;
use App\Services\RequisitionStockSnapshotService;
use App\Services\RequisitionWorkflowNotificationService;
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
    use SwitchesUcSentTab;

    protected static string $resource = RequisitionResource::class;

    #[Url(as: 'uc')]
    public ?string $ucTab = null;

    #[Url(as: 'uc_office')]
    public ?int $ucOfficeId = null;

    #[Url(as: 'uc_dept')]
    public ?int $ucDepartmentId = null;

    #[Url]
    public ?int $create = null;

    #[Url]
    public ?int $item_id = null;

    #[Url]
    public ?int $category = null;

    public function mount(): void
    {
        parent::mount();

        $this->initializeUcListScope();

        static $pollHookRegistered = false;

        if (! $pollHookRegistered) {
            $pollHookRegistered = true;

            FilamentView::registerRenderHook(
                PanelsRenderHook::PAGE_END,
                function (): ?HtmlString {
                    $interval = $this->requisitionRefreshPollingInterval();

                    if (! filled($interval)) {
                        return null;
                    }

                    return new HtmlString(
                        '<div wire:poll.'.$interval.'="$refresh" class="hidden" aria-hidden="true"></div>',
                    );
                },
                scopes: static::class,
            );
        }

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

        if ($user?->isEmployee()) {
            return [
                'active' => Tab::make('Active')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query
                        ->whereNull('archived_at')
                        ->whereIn('status', [
                            Requisition::STATUS_DRAFT,
                            Requisition::STATUS_PENDING,
                            Requisition::STATUS_ACCEPTED,
                        ]))
                    ->excludeQueryWhenResolvingRecord(),
                'archived' => Tab::make('Archived')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                        $query->whereNotNull('archived_at')
                            ->orWhere('status', Requisition::STATUS_REJECTED);
                    }))
                    ->excludeQueryWhenResolvingRecord(),
            ];
        }

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
            ];
        }

        // Supply Custodian: single list (no Active/Archived tabs; SC does not reject).
        return [];
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
                        (string) view('filament.tables.requisitions-uc-toolbar-secondary', [
                            'activeUcTab' => $this->ucTab ?? 'received',
                            'ucOfficeId' => $this->ucOfficeId,
                            'ucDepartmentId' => $this->ucDepartmentId,
                            'officeOptions' => $this->getUcOfficeOptions(),
                            'departmentOptions' => $this->getUcDepartmentOptions(),
                            'scopeComplete' => $this->ucListScopeIsComplete(),
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
                'Export RIS',
            )->visible(fn (): bool => RequisitionExportActions::userCanExportRis(
                Filament::auth()->user() instanceof User ? Filament::auth()->user() : null,
            )),
            OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_WIDE)
                ->modalWidth(fn (): string => Filament::auth()->user()?->isEmployee()
                    ? OwwaFormModalDefaults::WIDTH_MEDIUM
                    : OwwaFormModalDefaults::WIDTH_WIDE)
                ->extraModalWindowAttributes(fn (): array => [
                    'class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS
                        .(Filament::auth()->user()?->isEmployee()
                            ? ' owwa-requisition-employee-modal'
                            : (Filament::auth()->user()?->isUnitConsolidator() ? ' owwa-requisition-uc-modal' : '')),
                ])
                ->label(fn (): string => Filament::auth()->user()?->isUnitConsolidator()
                    ? 'New Requisition to Supply Custodian'
                    : 'New Requisition')
                ->createAnother(false)
                ->closeModalByClickingAway(fn (): bool => ! (Filament::auth()->user()?->isUnitConsolidator() ?? false))
                ->closeModalByEscaping(fn (): bool => ! (Filament::auth()->user()?->isUnitConsolidator() ?? false))
                ->modalCancelActionLabel('Cancel')
                ->modalSubmitActionLabel(fn (): string => Filament::auth()->user()?->isEmployee()
                    ? 'Save draft'
                    : 'Create')
                ->extraModalFooterActions(function (Action $createAction): array {
                    if (! (Filament::auth()->user()?->isEmployee() ?? false)) {
                        return [];
                    }

                    return [
                        $createAction
                            ->makeModalSubmitAction('submitToConsolidator', [
                                'workflow' => EmployeeRequisitionActions::WORKFLOW_SUBMIT,
                            ])
                            ->label('Submit to consolidator')
                            ->icon('heroicon-o-paper-airplane')
                            ->color('success'),
                    ];
                })
                ->after(function (Model $record, Action $action): void {
                    if (! $record instanceof Requisition) {
                        return;
                    }

                    /** @var User|null $user */
                    $user = Filament::auth()->user();

                    if ($user?->isUnitConsolidator()) {
                        app(RequisitionStockSnapshotService::class)->snapshotRequisitionLines($record);
                        $this->switchUcTabToSent();
                    }

                    if (($action->getArguments()['workflow'] ?? null) !== EmployeeRequisitionActions::WORKFLOW_SUBMIT) {
                        return;
                    }

                    try {
                        EmployeeRequisitionActions::submitRecord($record);
                    } catch (ValidationException $exception) {
                        $record->delete();

                        throw $exception;
                    }
                })
                ->mountUsing(function (Action $action, ?Schema $schema): void {
                    $arguments = $action->getArguments();

                    $itemId = (int) ($arguments['catalogPrefillItemId'] ?? 0);
                    $argOfficeId = (int) ($arguments['office_id'] ?? 0);
                    $argDepartmentId = (int) ($arguments['department_id'] ?? 0);
                    $sourceIds = array_values(array_filter(
                        array_map('intval', $arguments['prefillSourceRequisitionIds'] ?? []),
                    ));

                    $catalogPrefill = [];

                    if ($itemId > 0) {
                        $categoryId = $arguments['catalogPrefillCategoryId'] ?? null;

                        $catalogPrefill = RequisitionForm::catalogPrefillState(
                            $itemId,
                            filled($categoryId) ? (int) $categoryId : null,
                        );
                    }

                    /** @var User|null $user */
                    $user = Filament::auth()->user();

                    if (! $user?->isUnitConsolidator()) {
                        $schema?->fill($catalogPrefill !== [] ? $catalogPrefill : [
                            'items' => [
                                [
                                    'item_category_id' => null,
                                    'item_id' => null,
                                    'quantity' => null,
                                ],
                            ],
                        ]);

                        return;
                    }

                    $officeId = $argOfficeId > 0
                        ? $argOfficeId
                        : (($this->ucOfficeId !== null && $this->ucOfficeId > 0) ? $this->ucOfficeId : null);

                    $departmentId = $argDepartmentId > 0
                        ? $argDepartmentId
                        : (($this->ucDepartmentId !== null && $this->ucDepartmentId > 0) ? $this->ucDepartmentId : null);

                    if ($sourceIds === []) {
                        $schema?->fill([
                            'office_id' => $officeId,
                            'department_id' => $departmentId,
                            'source_requisition_ids' => [],
                            'endorsement_lines' => [],
                            'items' => $catalogPrefill['items'] ?? [
                                [
                                    'item_category_id' => null,
                                    'item_id' => null,
                                    'quantity' => null,
                                ],
                            ],
                        ]);

                        return;
                    }

                    $requisitions = Requisition::query()
                        ->whereIn('id', $sourceIds)
                        ->with(['items.item.category', 'requestedBy'])
                        ->get();

                    $compileService = app(RequisitionCompileService::class);
                    $endorsementLines = $compileService->buildEndorsementLines($requisitions);

                    // One-pass fill so office/dept live hooks do not clear preselected sources.
                    $schema?->fill([
                        'office_id' => $officeId,
                        'department_id' => $departmentId,
                        'source_requisition_ids' => $sourceIds,
                        'endorsement_lines' => $endorsementLines,
                        'items' => $compileService->mergedLineItemsAsRepeaterState(
                            $compileService->mergedLineItemsFromEndorsements($endorsementLines),
                        ),
                    ]);
                })
                ->modalHeading(fn (): string => Filament::auth()->user()?->isUnitConsolidator()
                    ? 'New Requisition to Supply Custodian'
                    : 'New Requisition')
                ->modalDescription(function (Action $action): string {
                    /** @var User|null $user */
                    $user = Filament::auth()->user();

                    if (! $user?->isUnitConsolidator()) {
                        return RequisitionForm::createModalDescription();
                    }

                    $sourceIds = array_values(array_filter(
                        array_map('intval', $action->getArguments()['prefillSourceRequisitionIds'] ?? []),
                    ));

                    if ($sourceIds !== []) {
                        return 'Review endorsed quantities and enter Purpose (RIS), then send to the Supply Custodian.';
                    }

                    return RequisitionForm::createModalDescription();
                })
                ->using(function (array $data, HasActions&HasSchemas $livewire): Model {
                    /** @var class-string<Model> $model */
                    $model = static::getResource()::getModel();

                    $sourceIds = array_values(array_filter($data['source_requisition_ids'] ?? []));
                    unset($data['source_requisition_ids']);

                    /** @var User|null $user */
                    $user = Filament::auth()->user();

                    if ($user?->isEmployee()) {
                        $data['status'] = Requisition::STATUS_DRAFT;
                    }

                    $endorsementLines = array_values($data['endorsement_lines'] ?? []);
                    unset($data['endorsement_lines']);

                    $itemRows = array_values($data['items'] ?? []);
                    unset($data['items']);

                    if ($user?->isUnitConsolidator() && $sourceIds !== []) {
                        app(RequisitionCompileService::class)->validateEndorsementLines($endorsementLines);
                    }

                    $record = new $model;
                    $record->fill(Arr::except($data, ['items']));
                    $record->save();

                    if ($user?->isUnitConsolidator()) {
                        $snapshotService = app(RequisitionStockSnapshotService::class);

                        if ($sourceIds !== []) {
                            $mergedItems = app(RequisitionCompileService::class)->mergedLineItemsFromEndorsements($endorsementLines);

                            foreach ($mergedItems as $row) {
                                RequisitionItem::query()->create([
                                    'requisition_id' => $record->id,
                                    'item_id' => (int) $row['item_id'],
                                    'quantity' => (int) $row['quantity'],
                                    'stock_at_request' => $snapshotService->regionalStockForItem((int) $row['item_id']),
                                ]);
                            }
                        } else {
                            foreach ($itemRows as $row) {
                                if (empty($row['item_id']) || empty($row['quantity'])) {
                                    continue;
                                }

                                $itemId = (int) $row['item_id'];

                                RequisitionItem::query()->create([
                                    'requisition_id' => $record->id,
                                    'item_id' => $itemId,
                                    'quantity' => (int) $row['quantity'],
                                    'stock_at_request' => $snapshotService->regionalStockForItem($itemId),
                                ]);
                            }
                        }
                    } elseif ($user?->isEmployee()) {
                        foreach ($itemRows as $row) {
                            if (empty($row['item_id']) || empty($row['quantity'])) {
                                continue;
                            }

                            RequisitionItem::query()->create([
                                'requisition_id' => $record->id,
                                'item_id' => (int) $row['item_id'],
                                'quantity' => (int) $row['quantity'],
                            ]);
                        }
                    }

                    if ($sourceIds !== []) {
                        /** @var User|null $user */
                        $user = Filament::auth()->user();

                        if ($user instanceof User) {
                            try {
                                app(RequisitionCompileService::class)->linkCompiledSources(
                                    $user,
                                    $record,
                                    $sourceIds,
                                    $endorsementLines,
                                );

                                app(RequisitionWorkflowNotificationService::class)
                                    ->handleEndorsedWithAdjustments($record, $endorsementLines);
                            } catch (\InvalidArgumentException $exception) {
                                $record->delete();

                                throw ValidationException::withMessages([
                                    'source_requisition_ids' => $exception->getMessage(),
                                ]);
                            } catch (ValidationException $exception) {
                                $record->delete();

                                throw $exception;
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

                    if ($user->isEmployee()) {
                        if (blank($user->office_id)) {
                            throw ValidationException::withMessages([
                                'office_id' => 'Your account has no Office assigned. Please contact the System Admin.',
                            ]);
                        }

                        $data['office_id'] = (int) $user->office_id;
                        $data['department_id'] = $user->department_id ? (int) $user->department_id : null;
                        $data['remarks'] = null;

                        return $data;
                    }

                    if ($user->isUnitConsolidator()) {
                        $officeId = (int) ($data['office_id'] ?? 0);
                        $departmentId = (int) ($data['department_id'] ?? 0);

                        if ($officeId <= 0 || $departmentId <= 0) {
                            throw ValidationException::withMessages([
                                'office_id' => 'Select the office and sub-office/department for this requisition.',
                            ]);
                        }

                        if (! $user->coversOfficeDepartment($officeId, $departmentId)) {
                            throw ValidationException::withMessages([
                                'office_id' => 'You are not assigned to handle this office and sub-office/department.',
                            ]);
                        }

                        $data['office_id'] = $officeId;
                        $data['department_id'] = $departmentId;

                        return $data;
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

            $query = $query
                ->whereHas('requestedBy', fn (Builder $q): Builder => $q->where('role', User::ROLE_EMPLOYEE))
                ->whereNull('compiled_into_requisition_id');

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

    public function refreshFromRequisitionBroadcast(): void
    {
        $this->resetTable();
    }
}
