<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ListensForRequisitionBroadcasts;
use App\Filament\Pages\MyInventory;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\Distribution;
use App\Models\Requisition;
use App\Models\User;
use App\Support\OwwaReferenceLabels;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

class EmployeeStatsWidget extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;
    use ListensForRequisitionBroadcasts;

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.employee-stats-widget';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isEmployee() ?? false;
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        [$yearStart, $yearEnd] = $this->yearBounds();

        $baseQuery = fn () => Requisition::query()
            ->where('requested_by', $user->id)
            ->whereBetween('created_at', [$yearStart, $yearEnd]);

        $totalRequests = $baseQuery()->count();
        $pending = $baseQuery()->where('status', Requisition::STATUS_PENDING)->count();

        $received = (int) Distribution::query()
            ->where('distributed_to', $user->id)
            ->whereBetween('distribution_date', [$yearStart, $yearEnd])
            ->sum('quantity');

        $distinctItems = Distribution::query()
            ->where('distributed_to', $user->id)
            ->whereBetween('distribution_date', [$yearStart, $yearEnd])
            ->distinct('item_id')
            ->count('item_id');

        return [
            Stat::make('Requests sent', $totalRequests)
                ->description('Total requests this year')
                ->descriptionIcon('heroicon-o-document-text')
                ->color('primary')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewRequestsSent')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Pending', $pending)
                ->description($pending > 0 ? 'Awaiting consolidator review' : 'All reviewed')
                ->descriptionIcon($pending > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($pending > 0 ? 'warning' : 'success')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewPendingRequests')",
                    'title' => 'Click to view details',
                ], merge: true),

            Stat::make('Items received', number_format($received))
                ->description("{$distinctItems} distinct ".($distinctItems === 1 ? 'item' : 'items').' distributed to you')
                ->descriptionIcon('heroicon-o-inbox-arrow-down')
                ->color('info')
                ->extraAttributes([
                    'class' => 'cursor-pointer owwa-stat-clickable',
                    'wire:click' => "mountAction('viewItemsReceived')",
                    'title' => 'Click to view details',
                ], merge: true),
        ];
    }

    public function viewRequestsSentAction(): Action
    {
        return $this->detailModalAction(
            'viewRequestsSent',
            'Requests sent (this year)',
            fn (): array => $this->requestsSentDetail(pendingOnly: false),
            RequisitionResource::getUrl('index'),
            'Open My Requisitions',
        );
    }

    public function viewPendingRequestsAction(): Action
    {
        return $this->detailModalAction(
            'viewPendingRequests',
            'Pending requests',
            fn (): array => $this->requestsSentDetail(pendingOnly: true),
            RequisitionResource::getUrl('index'),
            'Open My Requisitions',
        );
    }

    public function viewItemsReceivedAction(): Action
    {
        return $this->detailModalAction(
            'viewItemsReceived',
            'Items received (this year)',
            fn (): array => $this->itemsReceivedDetail(),
            MyInventory::getUrl(),
            'Open My Inventory',
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
    protected function requestsSentDetail(bool $pendingOnly): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return $this->emptyRequestsDetail($pendingOnly);
        }

        [$yearStart, $yearEnd] = $this->yearBounds();

        $query = Requisition::query()
            ->where('requested_by', $user->id)
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->latest('created_at')
            ->limit(100);

        if ($pendingOnly) {
            $query->where('status', Requisition::STATUS_PENDING);
        }

        $rows = $query->with('compiledIntoRequisition')->get()->map(fn (Requisition $requisition): array => [
            'transaction_number' => $requisition->displayTransactionNumber(),
            'ris_number' => $requisition->displayRisNumber(),
            'status' => ucfirst((string) $requisition->status),
            'purpose' => $requisition->purpose,
            'created' => optional($requisition->created_at)?->format('M j, Y') ?? null,
        ])->all();

        $columns = $pendingOnly
            ? [
                'transaction_number' => OwwaReferenceLabels::employeeRequisitionTransaction(),
                'purpose' => 'Purpose',
                'created' => 'Submitted',
            ]
            : [
                'transaction_number' => OwwaReferenceLabels::employeeRequisitionTransaction(),
                'ris_number' => OwwaReferenceLabels::requisition(),
                'status' => 'Status',
                'purpose' => 'Purpose',
                'created' => 'Submitted',
            ];

        return [
            'summary' => count($rows).' request'.(count($rows) === 1 ? '' : 's').' this year'.($pendingOnly ? ' awaiting consolidator review' : '').'.',
            'empty_title' => $pendingOnly ? 'No pending requests' : 'No requests sent',
            'empty_desc' => $pendingOnly
                ? 'You have no requisitions awaiting consolidator review this year.'
                : 'You have not submitted any requisitions this year.',
            'columns' => $columns,
            'numeric_keys' => [],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{summary: string|null, empty_title: string, empty_desc: string, columns: array<string, string>, numeric_keys: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    protected function itemsReceivedDetail(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return [
                'summary' => null,
                'empty_title' => 'No items received',
                'empty_desc' => 'No distributions recorded for you this year.',
                'columns' => [
                    'item' => 'Item',
                    'category' => 'Category',
                    'quantity' => 'Qty',
                    'date' => 'Received',
                    'from' => 'Distributed by',
                ],
                'numeric_keys' => ['quantity'],
                'rows' => [],
            ];
        }

        [$yearStart, $yearEnd] = $this->yearBounds();

        $distributions = Distribution::query()
            ->with(['item.category', 'distributedBy'])
            ->where('distributed_to', $user->id)
            ->whereBetween('distribution_date', [$yearStart, $yearEnd])
            ->latest('distribution_date')
            ->limit(100)
            ->get();

        $rows = $distributions->map(fn (Distribution $distribution): array => [
            'item' => $distribution->item?->name,
            'category' => $distribution->item?->category?->name,
            'quantity' => $distribution->quantity,
            'date' => optional($distribution->distribution_date)?->format('M j, Y'),
            'from' => $distribution->distributedBy?->name,
        ])->all();

        $totalQty = (int) $distributions->sum('quantity');

        return [
            'summary' => number_format($totalQty).' unit'.($totalQty === 1 ? '' : 's').' received across '.count($rows).' distribution'.(count($rows) === 1 ? '' : 's').' (showing up to 100).',
            'empty_title' => 'No items received',
            'empty_desc' => 'No distributions recorded for you this year.',
            'columns' => [
                'item' => 'Item',
                'category' => 'Category',
                'quantity' => 'Qty',
                'date' => 'Received',
                'from' => 'Distributed by',
            ],
            'numeric_keys' => ['quantity'],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{summary: string|null, empty_title: string, empty_desc: string, columns: array<string, string>, numeric_keys: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    protected function emptyRequestsDetail(bool $pendingOnly): array
    {
        return [
            'summary' => null,
            'empty_title' => $pendingOnly ? 'No pending requests' : 'No requests sent',
            'empty_desc' => 'Nothing to show.',
            'columns' => $pendingOnly
                ? [
                    'reference' => 'Reference',
                    'purpose' => 'Purpose',
                    'created' => 'Submitted',
                ]
                : [
                    'reference' => 'Reference',
                    'status' => 'Status',
                    'purpose' => 'Purpose',
                    'created' => 'Submitted',
                ],
            'numeric_keys' => [],
            'rows' => [],
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    protected function yearBounds(): array
    {
        return [now()->startOfYear(), now()->endOfYear()];
    }
}
