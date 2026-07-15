<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\Issuance;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\EmployeeDistributionInventoryService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class EmployeeCustody extends Page
{
    use WithPagination;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.employee-custody';

    #[Url]
    public int|string|null $employee = null;

    #[Url]
    public string $sortBy = 'distribution_date';

    #[Url]
    public string $sortDir = 'desc';

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES;

    #[Url]
    public string $custodyTab = EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND;

    public int $ledgerPage = 1;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isUnitConsolidator();
    }

    public function mount(): void
    {
        if (! EmployeeDistributionInventoryService::isValidCategory($this->category)) {
            $this->category = EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES;
        }
    }

    public function updatedEmployee(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        if (! EmployeeDistributionInventoryService::isValidCategory($this->category)) {
            $this->category = EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES;
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCustodyTab(): void
    {
        if (! EmployeeDistributionInventoryService::isValidCustodyTab($this->custodyTab)) {
            $this->custodyTab = EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND;
        }

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
        return 'View items distributed or issued to employees in your office.';
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

        if (! $user instanceof User) {
            return [];
        }

        return app(EmployeeDistributionInventoryService::class)->employeesInScopeForUnitConsolidator($user);
    }

    /** @return array{totalItems: int, totalQuantity: int, totalQuantityThisYear: int} */
    public function getInventorySummary(): array
    {
        $employee = $this->resolveSelectedEmployee();

        if (! $employee) {
            return ['totalItems' => 0, 'totalQuantity' => 0, 'totalQuantityThisYear' => 0];
        }

        return app(EmployeeDistributionInventoryService::class)->summaryFor($employee, $this->category, $this->custodyTab);
    }

    public function getInventoryRows(): LengthAwarePaginator
    {
        $employee = $this->resolveSelectedEmployee();

        if (! $employee) {
            return (new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1))->onEachSide(0);
        }

        return app(EmployeeDistributionInventoryService::class)->paginatedGroupedInventory(
            $employee,
            filled($this->search) ? $this->search : null,
            $this->sortBy,
            $this->sortDir,
            10,
            $this->category,
            $this->custodyTab,
        );
    }

    public function usesPropertyIssuanceView(): bool
    {
        return EmployeeDistributionInventoryService::usesPropertyIssuanceView($this->category);
    }

    public function sortByColumn(string $column): void
    {
        $allowed = $this->usesPropertyIssuanceView()
            ? ['item_name', 'quantity', 'distribution_date', 'distribution_count', 'last_distribution_date']
            : ['item_name', 'quantity', 'distribution_date', 'distribution_count'];

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
            app(EmployeeDistributionInventoryService::class)->assertUnitConsolidatorCanViewEmployee($uc, $employee);
            app(EmployeeDistributionInventoryService::class)->assertEmployeeOwnsPropertyItem(
                $employee,
                $itemId,
                $this->custodyTab,
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->ledgerPage = 1;

        if ($this->usesPropertyIssuanceView() && $this->custodyTab === EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND) {
            $this->mountAction('viewPropertyItemUnits', [
                'itemId' => $itemId,
            ]);

            return;
        }

        $this->mountAction('viewDistributionLedger', [
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
        if ($this->custodyTab !== EmployeeDistributionInventoryService::CUSTODY_TAB_ON_HAND) {
            return false;
        }

        $slug = $issuance->item?->category?->getTemplateSlug();

        return in_array($slug, [
            EmployeeDistributionInventoryService::CATEGORY_SEMI_EXPENDABLE,
            EmployeeDistributionInventoryService::CATEGORY_PPE,
        ], true);
    }

    public function openPropertyItemLedger(int $itemId): void
    {
        $this->openPropertyIssuanceLedger($itemId);
    }

    public function openDistributionLedger(int $itemId): void
    {
        $uc = Filament::auth()->user();
        $employee = $this->resolveSelectedEmployee();

        if (! $uc instanceof User || ! $employee) {
            abort(403);
        }

        try {
            app(EmployeeDistributionInventoryService::class)->assertUnitConsolidatorCanViewEmployee($uc, $employee);
            app(EmployeeDistributionInventoryService::class)->assertEmployeeOwnsItem($employee, $itemId);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->ledgerPage = 1;

        $this->mountAction('viewDistributionLedger', [
            'itemId' => $itemId,
        ]);
    }

    public function viewDistributionLedgerAction(): Action
    {
        return Action::make('viewDistributionLedger')
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading(function (): string {
                $ledger = $this->resolveMountedDistributionLedger();

                return $ledger['header']['item_name'].' — Distribution History';
            })
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.pages.partials.employee-distribution-ledger-modal',
                ['ledger' => $this->resolveMountedDistributionLedger()],
            )->render()));
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

        if (! $uc instanceof User || blank($this->employee)) {
            return null;
        }

        $employee = User::query()->find((int) $this->employee);

        if (! $employee instanceof User) {
            return null;
        }

        try {
            app(EmployeeDistributionInventoryService::class)->assertUnitConsolidatorCanViewEmployee($uc, $employee);
        } catch (AuthorizationException) {
            return null;
        }

        return $employee;
    }

    /**
     * @return array{
     *     header: array<string, string|null>,
     *     columns: array<string, array{label: string, tooltip?: string}|string>,
     *     rows: array<int, array<string, mixed>>,
     *     paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator
     * }
     */
    protected function resolveMountedDistributionLedger(): array
    {
        $employee = $this->resolveSelectedEmployee();
        $arguments = $this->getMountedAction()?->getArguments() ?? [];
        $itemId = (int) ($arguments['itemId'] ?? 0);

        if (! $employee) {
            abort(403);
        }

        if ($this->usesPropertyIssuanceView()) {
            return app(EmployeeDistributionInventoryService::class)->presentPropertyIssuanceLedgerPaginated(
                $employee,
                $itemId,
                max(1, $this->ledgerPage),
                10,
                $this->custodyTab,
            );
        }

        return app(EmployeeDistributionInventoryService::class)->presentLedgerPaginated(
            $employee,
            $itemId,
            max(1, $this->ledgerPage),
        );
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

        return app(EmployeeDistributionInventoryService::class)->presentPropertyItemUnits(
            $employee,
            $itemId,
            $this->custodyTab,
            $uc,
        );
    }
}
