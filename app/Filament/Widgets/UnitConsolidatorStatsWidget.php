<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ListensForRequisitionBroadcasts;
use App\Filament\Pages\OfficePropertyRegister;
use App\Filament\Resources\Distributions\DistributionResource;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\Distribution;
use App\Models\Requisition;
use App\Models\User;
use App\Services\OfficePropertyRegisterService;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaReferenceLabels;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class UnitConsolidatorStatsWidget extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;
    use ListensForRequisitionBroadcasts;

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = 3;

    protected string $view = 'filament.widgets.unit-consolidator-stats-widget';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isUnitConsolidator() ?? false;
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        [$yearStart, $yearEnd] = $this->yearBounds();

        $pendingEmployeeRequests = $this->pendingEmployeeRequestsQuery($user)
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->count();

        $nearingEul = app(OfficePropertyRegisterService::class)->countNearingExpiryForUser($user);

        $distributed = (int) Distribution::query()
            ->where('distributed_by', $user->id)
            ->whereBetween('distribution_date', [$yearStart, $yearEnd])
            ->sum('quantity');

        return [
            Stat::make('Pending employee requests', $pendingEmployeeRequests)
                ->description($pendingEmployeeRequests > 0 ? 'Employee requests awaiting action' : 'No pending requests')
                ->descriptionIcon($pendingEmployeeRequests > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($pendingEmployeeRequests > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewPendingEmployeeRequests')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Property nearing EUL', $nearingEul)
                ->description($nearingEul > 0 ? 'Semi-expendable useful life review' : 'No semi property nearing expiry')
                ->descriptionIcon($nearingEul > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($nearingEul > 0 ? 'warning' : 'gray')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewPropertyNearingEul')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Items distributed', number_format($distributed))
                ->description('Total items distributed this year')
                ->descriptionIcon('heroicon-o-gift')
                ->color('info')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewItemsDistributed')",
                    'title' => 'Click to view details',
                ], merge: true),
        ];
    }

    public function viewPendingEmployeeRequestsAction(): Action
    {
        return $this->detailModalAction(
            'viewPendingEmployeeRequests',
            'Pending employee requests',
            fn (): array => $this->pendingEmployeeRequestsDetail(),
            RequisitionResource::getUrl('index'),
            'Open Requisitions',
        );
    }

    public function viewPropertyNearingEulAction(): Action
    {
        $semiCategoryId = InventoryCategoryOptions::categoryIdsForSlug('semi_expendable')->first();

        return $this->detailModalAction(
            'viewPropertyNearingEul',
            'Property nearing EUL',
            fn (): array => $this->propertyNearingEulDetail(),
            OfficePropertyRegister::getUrl(array_filter([
                'category' => $semiCategoryId,
            ])),
            'Open Office Property Registry',
        );
    }

    public function viewItemsDistributedAction(): Action
    {
        return $this->detailModalAction(
            'viewItemsDistributed',
            'Items distributed (this year)',
            fn (): array => $this->itemsDistributedDetail(),
            DistributionResource::getUrl('index'),
            'Open Distributions',
        );
    }

    /**
     * @param  callable(): array{summary: string|null, empty_title: string, empty_desc: string, columns: array<string, string>, numeric_keys: array<int, string>, rows: array<int, array<string, mixed>>}  $detailResolver
     */
    protected function detailModalAction(
        string $name,
        string $heading,
        callable $detailResolver,
        string $pageUrl,
        string $pageLabel,
    ): Action {
        return Action::make($name)
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading($heading)
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.widgets.partials.employee-stats-detail-modal',
                ['detail' => $detailResolver()],
            )->render()))
            ->extraModalFooterActions([
                Action::make('openPage')
                    ->label($pageLabel)
                    ->url($pageUrl)
                    ->color('primary')
                    ->icon('heroicon-m-arrow-top-right-on-square'),
            ]);
    }

    /**
     * @return array{summary: string|null, empty_title: string, empty_desc: string, columns: array<string, string>, numeric_keys: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    protected function pendingEmployeeRequestsDetail(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return [
                'summary' => null,
                'empty_title' => 'No pending requests',
                'empty_desc' => 'Nothing to show.',
                'columns' => [
                    'transaction_number' => OwwaReferenceLabels::employeeRequisitionTransaction(),
                    'employee' => 'Employee',
                    'purpose' => 'Purpose',
                    'created' => 'Submitted',
                ],
                'numeric_keys' => [],
                'rows' => [],
            ];
        }

        [$yearStart, $yearEnd] = $this->yearBounds();

        $rows = $this->pendingEmployeeRequestsQuery($user)
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->with('requestedBy')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (Requisition $requisition): array => [
                'transaction_number' => $requisition->displayTransactionNumber(),
                'employee' => $requisition->requestedBy?->name,
                'purpose' => $requisition->purpose,
                'created' => optional($requisition->created_at)?->format('M j, Y'),
            ])
            ->all();

        return [
            'summary' => count($rows).' pending employee request'.(count($rows) === 1 ? '' : 's').' this year.',
            'empty_title' => 'No pending requests',
            'empty_desc' => 'There are no employee requisitions awaiting your review this year.',
            'columns' => [
                'transaction_number' => OwwaReferenceLabels::employeeRequisitionTransaction(),
                'employee' => 'Employee',
                'purpose' => 'Purpose',
                'created' => 'Submitted',
            ],
            'numeric_keys' => [],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{summary: string|null, empty_title: string, empty_desc: string, columns: array<string, string>, numeric_keys: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    protected function propertyNearingEulDetail(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return [
                'summary' => null,
                'empty_title' => 'No property nearing EUL',
                'empty_desc' => 'Nothing to show.',
                'columns' => [
                    'property_number' => 'Property no.',
                    'item' => 'Item',
                    'category' => 'Category',
                    'issued_to' => 'Issued to',
                    'expires_at' => 'Expires',
                    'status' => 'Status',
                ],
                'numeric_keys' => [],
                'rows' => [],
            ];
        }

        $rows = app(OfficePropertyRegisterService::class)->listNearingExpiryForUser($user);

        return [
            'summary' => count($rows).' semi-expendable unit'.(count($rows) === 1 ? '' : 's').' nearing or past useful life.',
            'empty_title' => 'No property nearing EUL',
            'empty_desc' => 'No semi-expendable property in your office is nearing or past useful life.',
            'columns' => [
                'property_number' => 'Property no.',
                'item' => 'Item',
                'category' => 'Category',
                'issued_to' => 'Issued to',
                'expires_at' => 'Expires',
                'status' => 'Status',
            ],
            'numeric_keys' => [],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{summary: string|null, empty_title: string, empty_desc: string, columns: array<string, string>, numeric_keys: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    protected function itemsDistributedDetail(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return [
                'summary' => null,
                'empty_title' => 'No items distributed',
                'empty_desc' => 'Nothing to show.',
                'columns' => [
                    'item' => 'Item',
                    'category' => 'Category',
                    'quantity' => 'Qty',
                    'employee' => 'Distributed to',
                    'date' => 'Date',
                ],
                'numeric_keys' => ['quantity'],
                'rows' => [],
            ];
        }

        [$yearStart, $yearEnd] = $this->yearBounds();

        $distributions = Distribution::query()
            ->with(['item.category', 'distributedTo'])
            ->where('distributed_by', $user->id)
            ->whereBetween('distribution_date', [$yearStart, $yearEnd])
            ->latest('distribution_date')
            ->limit(100)
            ->get();

        $rows = $distributions->map(fn (Distribution $distribution): array => [
            'item' => $distribution->item?->name,
            'category' => $distribution->item?->category?->name,
            'quantity' => $distribution->quantity,
            'employee' => $distribution->distributedTo?->name,
            'date' => optional($distribution->distribution_date)?->format('M j, Y'),
        ])->all();

        $totalQty = (int) $distributions->sum('quantity');

        return [
            'summary' => number_format($totalQty).' unit'.($totalQty === 1 ? '' : 's').' distributed across '.count($rows).' record'.(count($rows) === 1 ? '' : 's').' (showing up to 100).',
            'empty_title' => 'No items distributed',
            'empty_desc' => 'You have not distributed items to employees this year.',
            'columns' => [
                'item' => 'Item',
                'category' => 'Category',
                'quantity' => 'Qty',
                'employee' => 'Distributed to',
                'date' => 'Date',
            ],
            'numeric_keys' => ['quantity'],
            'rows' => $rows,
        ];
    }

    /**
     * @return Builder<Requisition>
     */
    protected function pendingEmployeeRequestsQuery(User $user): Builder
    {
        return Requisition::query()
            ->where('status', Requisition::STATUS_PENDING)
            ->whereHas('requestedBy', fn (Builder $q) => $q->where('role', User::ROLE_EMPLOYEE))
            ->when($user->office_id, fn ($q) => $q->where('office_id', $user->office_id))
            ->when($user->department_id, fn ($q) => $q->where('department_id', $user->department_id));
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    protected function yearBounds(): array
    {
        return [now()->startOfYear(), now()->endOfYear()];
    }
}
