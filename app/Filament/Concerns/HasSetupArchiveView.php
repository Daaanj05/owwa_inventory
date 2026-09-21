<?php

namespace App\Filament\Concerns;

use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

trait HasSetupArchiveView
{
    public bool $showingArchived = false;

    /**
     * @var array<class-string, true>
     */
    private static array $setupArchiveHooksRegistered = [];

    protected function registerSetupArchiveViewHook(): void
    {
        $class = static::class;
        if (isset(self::$setupArchiveHooksRegistered[$class])) {
            return;
        }

        self::$setupArchiveHooksRegistered[$class] = true;

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            function (): HtmlString {
                return new HtmlString(
                    (string) view('filament.tables.setup-archive-view-toggle', [
                        'showingArchived' => $this->showingArchived,
                        'archivedCount' => $this->setupArchiveViewArchivedCount(),
                    ])
                );
            },
            scopes: static::class,
        );
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
