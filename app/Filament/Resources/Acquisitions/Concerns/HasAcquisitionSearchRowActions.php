<?php

namespace App\Filament\Resources\Acquisitions\Concerns;

use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

/**
 * Places New/Create (+ Export) on the table search toolbar row.
 *
 * Call from content() every Livewire request (not only mount()).
 */
trait HasAcquisitionSearchRowActions
{
    /**
     * @param  (callable(object): bool)|null  $showCreate
     */
    protected function registerAcquisitionSearchRowActions(
        ?string $createActionName = null,
        ?string $createLabel = null,
        ?callable $showCreate = null,
    ): void {
        $requestKey = 'owwa.acquisition.search_row_actions.'.static::class;
        if (request()->attributes->get($requestKey)) {
            return;
        }

        request()->attributes->set($requestKey, true);

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            function () use ($createActionName, $createLabel, $showCreate): HtmlString {
                $livewire = Livewire::current();
                $page = is_object($livewire) && is_a($livewire, static::class) ? $livewire : $this;

                $visible = $createActionName !== null
                    && ($showCreate === null ? true : (bool) $showCreate($page));

                return new HtmlString(
                    (string) view('filament.resources.acquisitions.partials.search-row-actions', [
                        'showCreate' => $visible,
                        'createAction' => $createActionName,
                        'createLabel' => $createLabel ?? 'New',
                        'exportAction' => 'exportProcurementReport',
                    ])
                );
            },
            scopes: static::class,
        );
    }
}
