<?php

namespace App\Filament\Resources\Acquisitions\Concerns;

use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Active | Archive (or Received | Opening) icon toggle on the PR/PO/IAR/Received doc-tabs row.
 */
trait HasAcquisitionListViewToggle
{
    public bool $showingArchived = false;

    public bool $showingOpeningBalances = false;

    public ?string $filterDateFrom = null;

    public ?string $filterDateUntil = null;

    abstract protected function acquisitionListToggleMode(): string;

    abstract protected function acquisitionListToggleBadgeCount(): int;

    public function updatedShowingArchived(): void
    {
        $this->resetTable();
    }

    public function updatedShowingOpeningBalances(): void
    {
        $this->resetTable();
    }

    public function updatedFilterDateFrom(): void
    {
        $this->resetTable();
    }

    public function updatedFilterDateUntil(): void
    {
        $this->resetTable();
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return array_merge(parent::getPageClasses(), [
            'owwa-acquisition-list-page',
        ]);
    }

    protected function applyAcquisitionArchiveQuery(Builder $query): Builder
    {
        return $this->showingArchived
            ? $query->whereNotNull('archived_at')
            : $query->whereNull('archived_at');
    }

    protected function applyAcquisitionDateFilter(Builder $query, string $column): Builder
    {
        $from = filled($this->filterDateFrom) ? substr((string) $this->filterDateFrom, 0, 10) : null;
        $until = filled($this->filterDateUntil) ? substr((string) $this->filterDateUntil, 0, 10) : null;

        if ($from !== null && $until !== null && $from > $until) {
            return $query;
        }

        return $query
            ->when($from !== null, fn (Builder $q): Builder => $q->whereDate($column, '>=', $from))
            ->when($until !== null, fn (Builder $q): Builder => $q->whereDate($column, '<=', $until));
    }

    protected function acquisitionDateRangeHeader(): View
    {
        return View::make('filament.resources.acquisitions.partials.date-range-header');
    }

    protected function acquisitionViewToggleHtml(): HtmlString
    {
        $mode = $this->acquisitionListToggleMode();

        return new HtmlString(
            (string) view('filament.resources.acquisitions.partials.list-view-toggle', [
                'mode' => $mode,
                'showingArchived' => $this->showingArchived,
                'showingOpeningBalances' => $this->showingOpeningBalances,
                'badgeCount' => $this->acquisitionListToggleBadgeCount(),
            ])
        );
    }
}
