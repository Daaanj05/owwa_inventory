<?php

namespace App\Filament\Resources\PropertyActionRequests\Pages;

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
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ListPropertyActionRequests extends ListRecords
{
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

        if ($user?->isSupplyCustodian()) {
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
            ->modalHeading('Property Return')
            ->fillForm(function (array $arguments): array {
                $data = [];

                if (filled($arguments['action_type'] ?? null)) {
                    $data['action_type'] = $arguments['action_type'];
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
                    ]],
                ]);
            })
            ->mutateDataUsing(function (array $data): array {
                $user = Filament::auth()->user();

                return PropertyActionRequestForm::hydrateParentFromLines(
                    $data,
                    $user instanceof User ? $user : null,
                );
            })
            ->using(function (array $data): PropertyActionRequest {
                $record = new PropertyActionRequest;
                $record->fill($data);
                $record->save();

                return $record;
            })
            ->after(function (PropertyActionRequest $record, Action $action): void {
                $workflow = $action->getArguments()['workflow'] ?? null;

                if ($workflow === PropertyActionRequestEmployeeActions::WORKFLOW_SUBMIT) {
                    try {
                        PropertyActionRequestEmployeeActions::submitRecord($record);
                    } catch (ValidationException $exception) {
                        $record->delete();

                        throw $exception;
                    }
                }

                if ($workflow === PropertyActionRequestEmployeeActions::WORKFLOW_SEND_TO_SC) {
                    try {
                        PropertyActionRequestEmployeeActions::sendToSupplyCustodianRecord($record);
                        $this->switchUcTabToSent();
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
                ->modalSubmitActionLabel('Save draft')
                ->extraModalFooterActions(function (Action $createAction): array {
                    return PropertyActionRequestEmployeeActions::createUcModalFooterActions($createAction);
                })
                ->mountUsing(function (Action $action, ?Schema $schema): void {
                    $fill = [];

                    if ($this->ucOfficeId !== null && $this->ucOfficeId > 0) {
                        $fill['office_id'] = $this->ucOfficeId;
                    }

                    if ($this->ucDepartmentId !== null && $this->ucDepartmentId > 0) {
                        $fill['department_id'] = $this->ucDepartmentId;
                    }

                    if ($fill !== []) {
                        $schema?->fill($fill);
                    }
                });
        }

        return [
            $createAction,
        ];
    }

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
                        (string) view('filament.tables.property-returns-uc-toolbar-secondary', [
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

        return parent::content($schema);
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
