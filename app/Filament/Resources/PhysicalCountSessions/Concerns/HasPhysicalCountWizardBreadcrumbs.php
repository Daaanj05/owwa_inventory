<?php

namespace App\Filament\Resources\PhysicalCountSessions\Concerns;

use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use Illuminate\Support\HtmlString;

trait HasPhysicalCountWizardBreadcrumbs
{
    protected function activeCategoryId(): int
    {
        return (int) session('active_item_category_id', 0);
    }

    protected function activeCategoryName(): string
    {
        $name = ItemCategory::query()->whereKey($this->activeCategoryId())->value('name');

        return filled($name) ? (string) $name : 'Inventory';
    }

    protected function categoryDashboardUrl(): string
    {
        $categoryId = $this->activeCategoryId();

        if ($categoryId <= 0) {
            return InventoryCategoryDashboard::getUrl();
        }

        return InventoryCategoryDashboard::getUrl(['category' => $categoryId]);
    }

    protected function physicalCountListUrl(): string
    {
        $url = PhysicalCountSessionResource::getUrl('index');
        $categoryId = $this->activeCategoryId();

        if ($categoryId <= 0) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query(['category' => $categoryId]);
    }

    protected function sessionViewUrl(PhysicalCountSession $session): string
    {
        return PhysicalCountSessionResource::viewModalUrl($session);
    }

    /**
     * @param  array<int, array{label: string, url?: string|null}>  $segments
     */
    protected function wizardBreadcrumbHtml(array $segments): string
    {
        $parts = [];

        foreach ($segments as $index => $segment) {
            $label = e($segment['label']);
            $url = $segment['url'] ?? null;
            $isLast = $index === count($segments) - 1;

            if ($isLast || blank($url)) {
                $parts[] = sprintf(
                    '<span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">%s</span>',
                    $label,
                );
            } else {
                $parts[] = sprintf(
                    '<a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">%s</a>',
                    e($url),
                    $label,
                );
            }

            if (! $isLast) {
                $parts[] = '<span class="owwa-wizard-separator" aria-hidden="true">&gt;</span>';
            }
        }

        return sprintf(
            '<span class="owwa-wizard-title" role="list">%s</span>',
            implode('', $parts),
        );
    }

    /**
     * @param  array<int, array{label: string, url?: string|null}>  $tailSegments
     */
    protected function physicalCountBreadcrumbHtml(array $tailSegments = []): HtmlString
    {
        $segments = [
            [
                'label' => $this->activeCategoryName(),
                'url' => $this->categoryDashboardUrl(),
            ],
            [
                'label' => 'Physical counts',
                'url' => count($tailSegments) > 0 ? $this->physicalCountListUrl() : null,
            ],
            ...$tailSegments,
        ];

        if (count($tailSegments) === 0) {
            $segments[1]['url'] = null;
        }

        return new HtmlString($this->wizardBreadcrumbHtml($segments));
    }

    /**
     * @param  array<int, array{label: string, url?: string|null}>  $tailSegments
     */
    protected function physicalCountSessionBreadcrumbHtml(PhysicalCountSession $session, array $tailSegments = []): HtmlString
    {
        $segments = [
            [
                'label' => $this->activeCategoryName(),
                'url' => $this->categoryDashboardUrl(),
            ],
            [
                'label' => 'Physical counts',
                'url' => $this->physicalCountListUrl(),
            ],
            [
                'label' => $session->reference_code,
                'url' => count($tailSegments) > 0 ? $this->sessionViewUrl($session) : null,
            ],
            ...$tailSegments,
        ];

        if (count($tailSegments) === 0) {
            $segments[2]['url'] = null;
        }

        return new HtmlString($this->wizardBreadcrumbHtml($segments));
    }

    protected function syncActiveCategoryFromSession(?PhysicalCountSession $session): void
    {
        if ($session?->item_category_id) {
            session()->put('active_item_category_id', $session->item_category_id);
        }
    }
}
