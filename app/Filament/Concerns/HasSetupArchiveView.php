<?php

namespace App\Filament\Concerns;

use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

trait HasSetupArchiveView
{
    public bool $showingArchived = false;

    protected bool $setupArchiveHookRegistered = false;

    /**
     * Re-register on every Livewire request. A static flag caused the Active/Archive
     * icons to vanish after the first AJAX update (mount does not re-run).
     */
    public function bootHasSetupArchiveView(): void
    {
        $this->registerSetupArchiveViewHook();
    }

    protected function registerSetupArchiveViewHook(): void
    {
        if ($this->setupArchiveHookRegistered) {
            return;
        }

        $this->setupArchiveHookRegistered = true;

        $scope = static::class;

        FilamentView::registerRenderHook(
            $this->setupArchiveViewRenderHook(),
            function () use ($scope): HtmlString {
                $livewire = Livewire::current();

                if (! is_object($livewire) || ! is_a($livewire, $scope)) {
                    return new HtmlString('');
                }

                /** @var self $livewire */
                return new HtmlString(
                    (string) view('filament.tables.setup-archive-view-toggle', [
                        'showingArchived' => $livewire->showingArchived,
                        'archivedCount' => $livewire->setupArchiveViewArchivedCount(),
                    ])
                );
            },
            scopes: $scope,
        );
    }

    protected function setupArchiveViewRenderHook(): string
    {
        return TablesRenderHook::TOOLBAR_SEARCH_AFTER;
    }

    abstract protected function setupArchiveViewArchivedCount(): int;

    public function updatedShowingArchived(): void
    {
        $this->resetTable();
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return array_merge(parent::getPageClasses(), [
            'owwa-setup-archive-toggle',
        ]);
    }

    protected function applySetupArchiveQuery(Builder $query): Builder
    {
        return $this->showingArchived
            ? $query->whereNotNull('archived_at')
            : $query->whereNull('archived_at');
    }
}
