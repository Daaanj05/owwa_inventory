<?php

namespace App\Filament\Resources\Acquisitions\Concerns;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\InspectionAcceptanceReportResource;
use App\Filament\Resources\Acquisitions\PurchaseOrders\PurchaseOrderResource;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

trait HasAcquisitionDocumentTabs
{
    protected bool $acquisitionDocumentTabsHookRegistered = false;

    protected function registerAcquisitionDocumentTabsBelowSearch(string $active): void
    {
        if ($this->acquisitionDocumentTabsHookRegistered) {
            return;
        }

        $this->acquisitionDocumentTabsHookRegistered = true;

        $scope = static::class;

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_AFTER,
            function () use ($active, $scope): HtmlString {
                $livewire = Livewire::current();

                if (! is_object($livewire) || ! is_a($livewire, $scope)) {
                    return new HtmlString('');
                }

                /** @var self $livewire */
                return new HtmlString(
                    (string) view('filament.resources.acquisitions.partials.document-tabs', [
                        'active' => $active,
                        'tabsHtml' => $livewire->acquisitionDocumentTabsHtml($active),
                    ])
                );
            },
        );
    }

    public function acquisitionDocumentTabsHtml(string $active): HtmlString
    {
        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();
        $params = $categoryId > 0 ? ['category' => $categoryId] : [];

        $tabs = [
            'pr' => [
                'label' => 'PR',
                'url' => AcquisitionResource::getUrl('index', $params),
            ],
            'po' => [
                'label' => 'PO',
                'url' => PurchaseOrderResource::getUrl('index', $params),
            ],
            'iar' => [
                'label' => 'IAR',
                'url' => InspectionAcceptanceReportResource::getUrl('index', $params),
            ],
            'received' => [
                'label' => 'Received',
                'url' => AcquisitionResource::getUrl('received', $params),
            ],
        ];

        $links = collect($tabs)->map(function (array $tab, string $key) use ($active): string {
            $classes = $key === $active
                ? 'owwa-pa-view-tab owwa-acquisition-doc-tab is-active'
                : 'owwa-pa-view-tab owwa-acquisition-doc-tab';

            return sprintf(
                '<a href="%s" class="%s" role="tab" aria-selected="%s">%s</a>',
                e($tab['url']),
                e($classes),
                $key === $active ? 'true' : 'false',
                e($tab['label']),
            );
        })->implode('');

        return new HtmlString(
            '<div class="owwa-pa-view-tabs owwa-stock-restock-tabs owwa-acquisition-doc-tabs" role="tablist">'.$links.'</div>'
        );
    }

    protected function acquisitionWizardHeading(string $taskLabel): HtmlString
    {
        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();
        $categoryName = \App\Models\ItemCategory::query()->whereKey($categoryId)->value('name') ?? 'Inventory';
        $dashboardUrl = InventoryCategoryDashboard::getUrl(['category' => $categoryId]);

        return new HtmlString(sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">%s</a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">%s</span></span>',
            e($dashboardUrl),
            e($categoryName),
            e($taskLabel),
        ));
    }
}
