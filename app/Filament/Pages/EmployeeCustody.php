<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\EmployeeReceivedInventoryService;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Livewire\Component as LivewireComponent;
use Livewire\WithPagination;

class EmployeeCustody extends Page
{
    use StartsOwwaExportBusy;
    use WithPagination;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.employee-custody';

    #[Url]
    public int|string|null $employee = null;

    #[Url(as: 'uc_office')]
    public ?int $ucOfficeId = null;

    #[Url(as: 'uc_dept')]
    public ?int $ucDepartmentId = null;

    #[Url]
    public string $sortBy = 'received_date';

    #[Url]
    public string $sortDir = 'desc';

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = EmployeeReceivedInventoryService::CATEGORY_CONSUMABLES;

    #[Url]
    public string $custodyTab = EmployeeReceivedInventoryService::CUSTODY_TAB_ON_HAND;

    #[Url]
    public ?string $fromDate = null;

    #[Url]
    public ?string $toDate = null;

    public int $ledgerPage = 1;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isUnitConsolidator();
    }

    public function mount(): void
    {
        if (! EmployeeReceivedInventoryService::isValidCategory($this->category)) {
            $this->category = EmployeeReceivedInventoryService::CATEGORY_CONSUMABLES;
        }

        $this->initializeUcListScope();

        if (blank($this->fromDate) && blank($this->toDate)) {
            $this->fromDate = now()->startOfYear()->toDateString();
            $this->toDate = now()->endOfYear()->toDateString();
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

        $this->clearEmployeeIfOutOfScope();
        $this->resetPage();
    }

    public function updatedUcDepartmentId(): void
    {
        $this->clearEmployeeIfOutOfScope();
        $this->resetPage();
    }

    public function updatedEmployee(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        if (! EmployeeReceivedInventoryService::isValidCategory($this->category)) {
            $this->category = EmployeeReceivedInventoryService::CATEGORY_CONSUMABLES;
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCustodyTab(): void
    {
        if (! EmployeeReceivedInventoryService::isValidCustodyTab($this->custodyTab)) {
            $this->custodyTab = EmployeeReceivedInventoryService::CUSTODY_TAB_ON_HAND;
        }

        $this->resetPage();
    }

    public function updatedFromDate(): void
    {
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->resetPage();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Employee Custody';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Employee Custody';
    }

    public function getSubheading(): ?string
    {
        return 'View items issued to employees in your office.';
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return ['owwa-inv-category-page', 'owwa-employee-custody'];
    }

    /** @return array<int, string> */
    public function getEmployeeOptions(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $this->ucListScopeIsComplete()) {
            return [];
        }

        return app(EmployeeReceivedInventoryService::class)->employeesForOfficeDepartment(
            $user,
            $this->ucOfficeId,
            $this->ucDepartmentId,
        );
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
            return $user->office_id
                ? Office::query()->active()->whereKey((int) $user->office_id)->orderBy('name')->pluck('name', 'id')->all()
                : [];
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
            return $user->department_id
                ? Department::query()
                    ->active()
                    ->where('office_id', $this->ucOfficeId)
                    ->whereKey((int) $user->department_id)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()
                : [];
        }

        return Department::query()
            ->active()
            ->where('office_id', $this->ucOfficeId)
            ->whereIn('id', $departmentIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array{totalItems: int, totalQuantity: int, totalQuantityThisYear: int} */
    public function getInventorySummary(): array
    {
        $employee = $this->resolveSelectedEmployee();

        if (! $employee) {
            return ['totalItems' => 0, 'totalQuantity' => 0, 'totalQuantityThisYear' => 0];
        }

        return app(EmployeeReceivedInventoryService::class)->summaryFor(
            $employee,
            $this->category,
            $this->custodyTab,
            $this->fromDate,
            $this->toDate,
        );
    }

    public function getInventoryRows(): LengthAwarePaginator
    {
        $employee = $this->resolveSelectedEmployee();

        if (! $employee) {
            return (new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1))->onEachSide(0);
        }

        return app(EmployeeReceivedInventoryService::class)->paginatedGroupedInventory(
            $employee,
            filled($this->search) ? $this->search : null,
            $this->sortBy,
            $this->sortDir,
            10,
            $this->category,
            $this->custodyTab,
            $this->fromDate,
            $this->toDate,
        );
    }

    public function usesPropertyIssuanceView(): bool
    {
        return EmployeeReceivedInventoryService::usesPropertyIssuanceView($this->category);
    }

    public function sortByColumn(string $column): void
    {
        $allowed = $this->usesPropertyIssuanceView()
            ? ['item_name', 'quantity', 'received_date', 'receipt_count', 'last_received_date']
            : ['item_name', 'quantity', 'received_date', 'receipt_count'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    public function openPropertyIssuanceLedger(int $itemId): void
    {
        $uc = Filament::auth()->user();
        $employee = $this->resolveSelectedEmployee();

        if (! $uc instanceof User || ! $employee) {
            abort(403);
        }

        try {
            app(EmployeeReceivedInventoryService::class)->assertUnitConsolidatorCanViewEmployee($uc, $employee);
            app(EmployeeReceivedInventoryService::class)->assertEmployeeOwnsPropertyItem(
                $employee,
                $itemId,
                $this->custodyTab,
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->ledgerPage = 1;

        if ($this->usesPropertyIssuanceView() && $this->custodyTab === EmployeeReceivedInventoryService::CUSTODY_TAB_ON_HAND) {
            $this->mountAction('viewPropertyItemUnits', [
                'itemId' => $itemId,
            ]);

            return;
        }

        $this->mountAction('viewReceivedLedger', [
            'itemId' => $itemId,
        ]);
    }

    public function propertyActionUrl(Issuance $issuance, string $actionType): string
    {
        return PropertyActionRequestResource::createUrlForIssuance($issuance->id, $actionType);
    }

    public function suggestedPropertyActionType(Issuance $issuance): string
    {
        return PropertyActionRequest::ACTION_RETURN;
    }

    public function showPropertyActionCta(Issuance $issuance): bool
    {
        if ($this->custodyTab !== EmployeeReceivedInventoryService::CUSTODY_TAB_ON_HAND) {
            return false;
        }

        $slug = $issuance->item?->category?->getTemplateSlug();

        return in_array($slug, [
            EmployeeReceivedInventoryService::CATEGORY_SEMI_EXPENDABLE,
            EmployeeReceivedInventoryService::CATEGORY_PPE,
        ], true);
    }

    public function openPropertyItemLedger(int $itemId): void
    {
        $this->openPropertyIssuanceLedger($itemId);
    }

    public function openReceivedLedger(int $itemId): void
    {
        $uc = Filament::auth()->user();
        $employee = $this->resolveSelectedEmployee();

        if (! $uc instanceof User || ! $employee) {
            abort(403);
        }

        try {
            app(EmployeeReceivedInventoryService::class)->assertUnitConsolidatorCanViewEmployee($uc, $employee);
            app(EmployeeReceivedInventoryService::class)->assertEmployeeOwnsItem($employee, $itemId);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->ledgerPage = 1;

        $this->mountAction('viewReceivedLedger', [
            'itemId' => $itemId,
        ]);
    }

    public function employeeReceivedExportUrl(?int $itemId = null): string
    {
        $employee = $this->resolveSelectedEmployee();

        if (! $employee) {
            return '#';
        }

        return route('owwa.export.employee-received', array_filter([
            'employee' => $employee,
            'category' => $this->category,
            'custody_tab' => $this->custodyTab,
            'from' => filled($this->fromDate) ? $this->fromDate : null,
            'to' => filled($this->toDate) ? $this->toDate : null,
            'item' => $itemId,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    public function exportAllItems(): void
    {
        $url = $this->employeeReceivedExportUrl();

        if ($url === '#') {
            return;
        }

        OwwaExportBusyDispatcher::start(
            $this,
            $url,
            'Preparing Excel export…',
            'Building one sheet per item for the selected period…',
        );
    }

    public function viewReceivedLedgerAction(): Action
    {
        return Action::make('viewReceivedLedger')
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal owwa-employee-custody-ledger-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading(function (): string {
                $ledger = $this->resolveMountedReceivedLedger();

                return $ledger['header']['item_name'].' — Received History';
            })
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.pages.partials.employee-received-ledger-modal',
                [
                    'ledger' => $this->resolveMountedReceivedLedger(),
                    'hideEndorsedBy' => true,
                ],
            )->render()))
            ->extraModalFooterActions([
                Action::make('exportThisItem')
                    ->label('Download this item')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function (Action $action): void {
                        $itemId = $this->mountedLedgerItemId();

                        if ($itemId <= 0) {
                            return;
                        }

                        $url = $this->employeeReceivedExportUrl($itemId);

                        if ($url === '#') {
                            return;
                        }

                        $livewire = $action->getLivewire();
                        OwwaExportBusyDispatcher::start(
                            $livewire instanceof LivewireComponent ? $livewire : $this,
                            $url,
                            'Preparing Excel export…',
                            'Building received history for this item…',
                        );
                    }),
            ]);
    }

    public function viewPropertyItemUnitsAction(): Action
    {
        return Action::make('viewPropertyItemUnits')
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading(function (): string {
                $ledger = $this->resolveMountedPropertyItemUnits();

                return ($ledger['header']['item_name'] ?? 'Item').' — Property Units';
            })
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.pages.partials.employee-property-item-units-modal',
                [
                    'ledger' => $this->resolveMountedPropertyItemUnits(),
                    'page' => $this,
                ],
            )->render()));
    }

    protected function resolveSelectedEmployee(): ?User
    {
        $uc = Filament::auth()->user();

        if (! $uc instanceof User || blank($this->employee) || ! $this->ucListScopeIsComplete()) {
            return null;
        }

        $employee = User::query()->find((int) $this->employee);

        if (! $employee instanceof User) {
            return null;
        }

        if ((int) $employee->office_id !== (int) $this->ucOfficeId
            || (int) $employee->department_id !== (int) $this->ucDepartmentId) {
            return null;
        }

        try {
            app(EmployeeReceivedInventoryService::class)->assertUnitConsolidatorCanViewEmployee($uc, $employee);
        } catch (AuthorizationException) {
            return null;
        }

        return $employee;
    }

    protected function initializeUcListScope(): void
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->isUnitConsolidator()) {
            return;
        }

        if ($user->hasSingleOfficeAssignment() || ($user->assignedOfficeIds() === [] && $user->office_id)) {
            $this->ucOfficeId ??= $user->assignedOfficeIds()[0] ?? (int) $user->office_id;
        }

        if ($this->ucOfficeId !== null
            && $this->ucOfficeId > 0
            && ($user->hasSingleDepartmentAssignmentForOffice($this->ucOfficeId)
                || ($user->assignedDepartmentIdsForOffice($this->ucOfficeId) === [] && $user->department_id))) {
            $departmentIds = $user->assignedDepartmentIdsForOffice($this->ucOfficeId);
            $this->ucDepartmentId ??= $departmentIds[0] ?? (int) $user->department_id;
        }
    }

    protected function clearEmployeeIfOutOfScope(): void
    {
        if (blank($this->employee)) {
            return;
        }

        $options = $this->getEmployeeOptions();

        if (! array_key_exists((int) $this->employee, $options)
            && ! array_key_exists((string) $this->employee, $options)) {
            $this->employee = null;
        }
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, array{label: string, tooltip?: string}|string>,
     *     rows: array<int, array<string, mixed>>,
     *     paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator
     * }
     */
    protected function resolveMountedReceivedLedger(): array
    {
        $employee = $this->resolveSelectedEmployee();
        $arguments = $this->mountedLedgerActionArguments();
        $itemId = (int) ($arguments['itemId'] ?? 0);

        if (! $employee) {
            abort(403);
        }

        if ($this->usesPropertyIssuanceView()) {
            return app(EmployeeReceivedInventoryService::class)->presentPropertyIssuanceLedgerPaginated(
                $employee,
                $itemId,
                max(1, $this->ledgerPage),
                10,
                $this->custodyTab,
                $this->fromDate,
                $this->toDate,
            );
        }

        return app(EmployeeReceivedInventoryService::class)->presentLedgerPaginated(
            $employee,
            $itemId,
            max(1, $this->ledgerPage),
            10,
            $this->fromDate,
            $this->toDate,
        );
    }

    protected function mountedLedgerItemId(): int
    {
        $arguments = $this->mountedLedgerActionArguments();

        return (int) ($arguments['itemId'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mountedLedgerActionArguments(): array
    {
        $parent = $this->getMountedAction(0);

        if ($parent !== null) {
            $arguments = $parent->getArguments();

            if (isset($arguments['itemId'])) {
                return $arguments;
            }
        }

        return $this->getMountedAction()?->getArguments() ?? [];
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>,
     *     custody_tab: string,
     *     category_slug: string|null
     * }
     */
    protected function resolveMountedPropertyItemUnits(): array
    {
        $uc = Filament::auth()->user();
        $employee = $this->resolveSelectedEmployee();
        $arguments = $this->getMountedAction()?->getArguments() ?? [];
        $itemId = (int) ($arguments['itemId'] ?? 0);

        if (! $uc instanceof User || ! $employee) {
            abort(403);
        }

        return app(EmployeeReceivedInventoryService::class)->presentPropertyItemUnits(
            $employee,
            $itemId,
            $this->custodyTab,
            $uc,
        );
    }
}
