<?php

namespace App\Support;

use App\Filament\Pages\InventoryCategoryDashboard;
use App\Models\ItemCategory;
use Illuminate\Support\HtmlString;

/**
 * Shared “[category icon] Category > Task” heading used across inventory module pages.
 */
final class CategoryWizardBreadcrumb
{
    /**
     * Map ItemCategory::getTemplateSlug() to CSS modifier for .owwa-wizard-step-icon--*.
     */
    public static function iconKeyFromTemplateSlug(?string $templateSlug): string
    {
        return match ($templateSlug) {
            'semi_expendable' => 'semi-expendable',
            'ppe' => 'ppe',
            'consumables' => 'consumables',
            default => 'default',
        };
    }

    public static function iconHtmlFromTemplateSlug(?string $templateSlug): string
    {
        return self::iconHtml(self::iconKeyFromTemplateSlug($templateSlug));
    }

    public static function iconHtml(?string $iconKey): string
    {
        if (blank($iconKey)) {
            return '';
        }

        $class = match ($iconKey) {
            'semi-expendable' => 'owwa-wizard-step-icon owwa-wizard-step-icon--semi-expendable',
            'ppe' => 'owwa-wizard-step-icon owwa-wizard-step-icon--ppe',
            'consumables' => 'owwa-wizard-step-icon owwa-wizard-step-icon--consumables',
            'default' => 'owwa-wizard-step-icon owwa-wizard-step-icon--default',
            default => 'owwa-wizard-step-icon owwa-wizard-step-icon--default',
        };

        return sprintf('<span class="%s" aria-hidden="true"></span>', $class);
    }

    public static function make(
        string $categoryName,
        string $taskLabel,
        int $categoryId,
        ?string $templateSlug = null,
    ): HtmlString {
        if ($templateSlug === null && $categoryId > 0) {
            $templateSlug = ItemCategory::query()->whereKey($categoryId)->first()?->getTemplateSlug();
        }

        $dashboardUrl = $categoryId > 0
            ? InventoryCategoryDashboard::getUrl(['category' => $categoryId])
            : InventoryCategoryDashboard::getUrl();

        $iconHtml = self::iconHtmlFromTemplateSlug($templateSlug);

        return new HtmlString(sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">%s<span class="owwa-wizard-step-label">%s</span></a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem"><span class="owwa-wizard-step-label">%s</span></span></span>',
            e($dashboardUrl),
            $iconHtml,
            e($categoryName),
            e($taskLabel),
        ));
    }

    /**
     * HTML string variant for callers that return string instead of HtmlString.
     */
    public static function makeHtml(
        string $categoryName,
        string $taskLabel,
        int $categoryId,
        ?string $templateSlug = null,
    ): string {
        return (string) self::make($categoryName, $taskLabel, $categoryId, $templateSlug);
    }
}
