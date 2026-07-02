<?php

namespace App\Filament\Resources\PhysicalCountSessions\Concerns;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use Illuminate\Support\HtmlString;

trait HasPhysicalCountWizardBreadcrumbs
{
    protected function activeCategoryId(): int
    {
        return SyncsActiveItemCategory::resolveCategoryIdFromContext(
            property_exists($this, 'category') && filled($this->category) ? (int) $this->category : null,
        );
    }

    protected function activeCategoryName(): string
    {
        $name = ItemCategory::query()->whereKey($this->activeCategoryId())->value('name');

        return filled($name) ? (string) $name : 'Inventory';
    }

    protected function activeCategoryTemplateSlug(): ?string
    {
        return ItemCategory::query()
            ->whereKey($this->activeCategoryId())
            ->first()
            ?->getTemplateSlug();
    }

    protected function activeCategoryIcon(): string
    {
        return match ($this->activeCategoryTemplateSlug()) {
            'semi_expendable' => 'semi-expendable',
            'ppe' => 'ppe',
            'consumables' => 'consumables',
            default => 'default',
        };
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
     * @param  array<int, array{label: string, url?: string|null, icon?: string|null}>  $segments
     */
    protected function wizardBreadcrumbHtml(array $segments): string
    {
        $parts = [];

        foreach ($segments as $index => $segment) {
            $label = e($segment['label']);
            $url = $segment['url'] ?? null;
            $icon = $segment['icon'] ?? null;
            $isLast = $index === count($segments) - 1;
            $iconHtml = $this->wizardStepIconHtml($icon);
            $stepClass = 'owwa-wizard-step'.($isLast ? ' owwa-wizard-step-current' : '');

            if ($isLast || blank($url)) {
                $parts[] = sprintf(
                    '<span class="%s" role="listitem">%s<span class="owwa-wizard-step-label">%s</span></span>',
                    $stepClass,
                    $iconHtml,
                    $label,
                );
            } else {
                $parts[] = sprintf(
                    '<a class="%s owwa-wizard-step-link" href="%s" role="listitem">%s<span class="owwa-wizard-step-label">%s</span></a>',
                    $stepClass,
                    e($url),
                    $iconHtml,
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

    protected function wizardStepIconHtml(?string $icon): string
    {
        if (blank($icon)) {
            return '';
        }

        $class = match ($icon) {
            'semi-expendable' => 'owwa-wizard-step-icon owwa-wizard-step-icon--semi-expendable',
            'ppe' => 'owwa-wizard-step-icon owwa-wizard-step-icon--ppe',
            'consumables' => 'owwa-wizard-step-icon owwa-wizard-step-icon--consumables',
            'default' => 'owwa-wizard-step-icon owwa-wizard-step-icon--default',
            default => 'owwa-wizard-step-icon',
        };

        return sprintf('<span class="%s" aria-hidden="true"></span>', $class);
    }

    /**
     * @param  array<int, array{label: string, url?: string|null, icon?: string|null}>  $tailSegments
     */
    protected function physicalCountBreadcrumbHtml(array $tailSegments = []): HtmlString
    {
        $segments = [
            [
                'label' => $this->activeCategoryName(),
                'icon' => $this->activeCategoryIcon(),
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
     * @param  array<int, array{label: string, url?: string|null, icon?: string|null}>  $tailSegments
     */
    protected function physicalCountSessionBreadcrumbHtml(PhysicalCountSession $session, array $tailSegments = []): HtmlString
    {
        $segments = [
            [
                'label' => $this->activeCategoryName(),
                'icon' => $this->activeCategoryIcon(),
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
