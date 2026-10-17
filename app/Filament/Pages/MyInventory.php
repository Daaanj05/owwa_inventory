<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Models\Issuance;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\EmployeeReceivedInventoryService;
use App\Services\UsefulLifeConditionReportService;
use App\Support\SemiExpendableUsefulLife;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class MyInventory extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'My Inventory';

    protected static ?string $title = 'My Inventory';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.my-inventory';

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
    public ?int $ledgerItem = null;

    public int $ledgerPage = 1;

    /** @var array<int, string> */
    public array $usefulLifeNotes = [];

    public function mount(): void
    {
        if (! EmployeeReceivedInventoryService::isValidCategory($this->category)) {
            $this->category = EmployeeReceivedInventoryService::CATEGORY_CONSUMABLES;
        }

        if ($this->ledgerItem !== null) {
            $this->openReceivedLedger($this->ledgerItem);
        }
    }

    public function updatedCategory(): void
    {
        if (! EmployeeReceivedInventoryService::isValidCategory($this->category)) {
            $this->category = EmployeeReceivedInventoryService::CATEGORY_CONSUMABLES;
        }

        $this->resetPage();
    }

    public function updatedCustodyTab(): void
    {
        if (! EmployeeReceivedInventoryService::isValidCustodyTab($this->custodyTab)) {
            $this->custodyTab = EmployeeReceivedInventoryService::CUSTODY_TAB_ON_HAND;
        }

        $this->resetPage();
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isEmployee();
    }

    public function getTitle(): string|Htmlable
    {
        return 'My Inventory';
    }

    public function getHeading(): string|Htmlable
    {
        return 'My Inventory';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    /** @return array<int, string> */
    public function getPageClasses(): array
    {
        return ['owwa-inv-category-page', 'owwa-employee-my-inventory'];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
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

    /** @return array{totalItems: int, totalQuantity: int, totalQuantityThisYear: int} */
    public function getInventorySummary(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return ['totalItems' => 0, 'totalQuantity' => 0, 'totalQuantityThisYear' => 0];
        }

        return app(EmployeeReceivedInventoryService::class)->summaryFor($user, $this->category, $this->custodyTab);
    }

    public function getInventoryRows(): LengthAwarePaginator
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return (new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1))->onEachSide(0);
        }

        return app(EmployeeReceivedInventoryService::class)->paginatedGroupedInventory(
            $user,
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
        return EmployeeReceivedInventoryService::usesPropertyIssuanceView($this->category);
    }

    public function reportStillUsable(int $issuanceId): void
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $issuance = $this->issuanceForUsefulLifeReport($user, $issuanceId);

        try {
            app(UsefulLifeConditionReportService::class)->reportStillUsable(
                $issuance,
                $user,
                (string) ($this->usefulLifeNotes[$issuanceId] ?? ''),
            );
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Could not report condition')
                ->body(collect($exception->errors())->flatten()->first() ?: 'Add a short note.')
                ->danger()
                ->send();

            return;
        }

        unset($this->usefulLifeNotes[$issuanceId]);

        Notification::make()
            ->title('Supply custodian notified')
            ->body('The useful life date was not changed.')
            ->success()
            ->send();
    }

    public function reportNeedsReplacement(int $issuanceId): void
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $issuance = $this->issuanceForUsefulLifeReport($user, $issuanceId);
        $url = app(UsefulLifeConditionReportService::class)->reportNeedsReplacement($issuance, $user);

        $this->redirect($url);
    }

    protected function issuanceForUsefulLifeReport(User $user, int $issuanceId): Issuance
    {
        app(EmployeeReceivedInventoryService::class)->assertEmployeeOwnsIssuance($user, $issuanceId);

        $issuance = Issuance::query()->with('item.category')->findOrFail($issuanceId);

        return $issuance;
    }

    public function propertyActionUrl(Issuance $issuance, string $actionType): string
    {
        return PropertyActionRequestResource::createUrlForIssuance($issuance->id, $actionType);
    }

    public function suggestedPropertyActionType(Issuance $issuance): string
    {
        $slug = $issuance->item?->category?->getTemplateSlug();

        if ($slug === 'semi_expendable') {
            $status = SemiExpendableUsefulLife::statusForIssuance($issuance);

            if (in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true)) {
                return PropertyActionRequest::ACTION_REPLACEMENT;
            }
        }

        return PropertyActionRequest::ACTION_DISPOSAL;
    }

    public function showPropertyActionCta(Issuance $issuance): bool
    {
        if ($this->custodyTab !== EmployeeReceivedInventoryService::CUSTODY_TAB_ON_HAND) {
            return false;
        }

        $slug = $issuance->item?->category?->getTemplateSlug();

        if ($slug !== 'semi_expendable') {
            return false;
        }

        $status = SemiExpendableUsefulLife::statusForIssuance($issuance);

        return in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true);
    }

    public function openReceivedLedger(int $itemId): void
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        try {
            app(EmployeeReceivedInventoryService::class)->assertEmployeeOwnsItem($user, $itemId);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->ledgerPage = 1;

        $this->mountAction('viewReceivedLedger', [
            'itemId' => $itemId,
        ]);
    }

    public function viewReceivedLedgerAction(): Action
    {
        return Action::make('viewReceivedLedger')
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading(function (): string {
                $ledger = $this->resolveMountedReceivedLedger();

                return $ledger['header']['item_name'].' — Received History';
            })
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.pages.partials.employee-received-ledger-modal',
                ['ledger' => $this->resolveMountedReceivedLedger()],
            )->render()));
    }

    public function openPropertyIssuanceLedger(int $itemId): void
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        try {
            app(EmployeeReceivedInventoryService::class)->assertEmployeeOwnsPropertyItem(
                $user,
                $itemId,
                $this->custodyTab,
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->ledgerPage = 1;

        $this->mountAction('viewReceivedLedger', [
            'itemId' => $itemId,
        ]);
    }

    public function openPropertyItemLedger(int $itemId): void
    {
        $this->openPropertyIssuanceLedger($itemId);
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
        $user = Filament::auth()->user();
        $arguments = $this->getMountedAction()?->getArguments() ?? [];
        $itemId = (int) ($arguments['itemId'] ?? 0);

        if (! $user instanceof User) {
            abort(403);
        }

        if ($this->usesPropertyIssuanceView()) {
            return app(EmployeeReceivedInventoryService::class)->presentPropertyIssuanceLedgerPaginated(
                $user,
                $itemId,
                max(1, $this->ledgerPage),
                10,
                $this->custodyTab,
            );
        }

        return app(EmployeeReceivedInventoryService::class)->presentLedgerPaginated(
            $user,
            $itemId,
            max(1, $this->ledgerPage),
        );
    }
}
