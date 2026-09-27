<?php

namespace App\Filament\Concerns;

use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

trait HasSearchRowToolbarActions
{
    protected bool $searchRowToolbarHookRegistered = false;

    public function bootHasSearchRowToolbarActions(): void
    {
        $this->registerSearchRowToolbarActions();
    }

    protected function registerSearchRowToolbarActions(): void
    {
        if ($this->searchRowToolbarHookRegistered) {
            return;
        }

        $this->searchRowToolbarHookRegistered = true;

        $scope = static::class;

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            function () use ($scope): HtmlString {
                $livewire = Livewire::current();

                if (! is_object($livewire) || ! is_a($livewire, $scope)) {
                    return new HtmlString('');
                }

                /** @var self $livewire */
                $buttons = $livewire->searchRowToolbarButtons();

                if ($buttons === []) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    (string) view('filament.tables.search-row-actions', [
                        'buttons' => $buttons,
                    ])
                );
            },
            scopes: $scope,
        );
    }

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string}>
     */
    abstract protected function searchRowToolbarButtons(): array;
}
