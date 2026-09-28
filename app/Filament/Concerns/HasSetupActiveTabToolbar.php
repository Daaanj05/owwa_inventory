<?php

namespace App\Filament\Concerns;

use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

trait HasSetupActiveTabToolbar
{
    use HasSearchRowToolbarActions;

    protected bool $setupActiveTabHookRegistered = false;

    public function bootHasSetupActiveTabToolbar(): void
    {
        $this->registerSetupActiveTabIcons();
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        $classes = array_merge(parent::getPageClasses(), [
            'owwa-search-row-toolbar',
            'owwa-setup-archive-toggle',
        ]);

        if ($this->setupToolbarCreateLabel() === null) {
            $classes[] = 'owwa-archive-icons-on-search-row';
        }

        return $classes;
    }

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string, schema?: string}>
     */
    protected function searchRowToolbarButtons(): array
    {
        $label = $this->setupToolbarCreateLabel();

        if ($label === null) {
            return [];
        }

        return [
            [
                'label' => $label,
                'action' => 'create',
                'style' => 'primary',
            ],
        ];
    }

    public function cacheInteractsWithHeaderActions(): void
    {
        parent::cacheInteractsWithHeaderActions();

        if ($this->setupToolbarCreateLabel() !== null) {
            $this->cachedHeaderActions = [];
        }
    }

    protected function setupToolbarCreateLabel(): ?string
    {
        return null;
    }

    abstract protected function setupActiveTabArchivedCount(): int;

    protected function registerSetupActiveTabIcons(): void
    {
        if ($this->setupActiveTabHookRegistered) {
            return;
        }

        $this->setupActiveTabHookRegistered = true;

        $scope = static::class;

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            function () use ($scope): HtmlString {
                $livewire = Livewire::current();

                if (! is_object($livewire) || ! is_a($livewire, $scope)) {
                    return new HtmlString('');
                }

                /** @var self $livewire */
                $activeTab = $livewire->activeTab ?? 'active';

                return new HtmlString(
                    (string) view('filament.tables.setup-active-tab-toggle', [
                        'showingArchived' => $activeTab === 'archived',
                        'archivedCount' => $livewire->setupActiveTabArchivedCount(),
                    ])
                );
            },
            scopes: $scope,
        );
    }
}
