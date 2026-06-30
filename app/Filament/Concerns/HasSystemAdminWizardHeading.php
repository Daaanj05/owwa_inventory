<?php

namespace App\Filament\Concerns;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Matches Supply Custodian "wizard" headers (e.g. Consumables &gt; Stock levels): one bold title row
 * with a clickable parent segment, no duplicate Filament breadcrumb + h1 stack.
 *
 * When the current panel is not system-admin, Filament defaults are preserved.
 */
trait HasSystemAdminWizardHeading
{
    protected function isSystemAdminPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'system-admin';
    }

    /**
     * @return array<int|string, string|null>
     */
    public function getBreadcrumbs(): array
    {
        if ($this->isSystemAdminPanel()) {
            return [];
        }

        return parent::getBreadcrumbs();
    }

    public function getHeading(): string|Htmlable|null
    {
        if (! $this->isSystemAdminPanel()) {
            return parent::getHeading();
        }

        $resource = static::getResource();
        $indexUrl = $resource::getUrl('index');
        $parentLabel = $resource::getTitleCasePluralModelLabel();

        if ($this instanceof EditRecord) {
            $currentLabel = $this->formatWizardSegment($this->getRecordTitle());

            return new HtmlString($this->buildWizardHeadingHtml($indexUrl, $parentLabel, $currentLabel));
        }

        if ($this instanceof CreateRecord) {
            $currentLabel = 'Create '.$resource::getTitleCaseModelLabel();

            $currentLabel = $this->formatWizardSegment($currentLabel);

            return new HtmlString($this->buildWizardHeadingHtml($indexUrl, $parentLabel, $currentLabel));
        }

        if ($this instanceof ListRecords) {
            return new HtmlString($this->buildSingleSegmentHeadingHtml($indexUrl, $parentLabel));
        }

        return parent::getHeading();
    }

    protected function formatWizardSegment(string|Htmlable $title): string
    {
        if ($title instanceof Htmlable) {
            $title = trim(strip_tags($title->toHtml()));
        }

        return Str::of($title)->trim()->ucfirst()->toString();
    }

    protected function buildWizardHeadingHtml(string $indexUrl, string $parentLabel, string $currentLabel): string
    {
        return sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">%s</a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">%s</span></span>',
            e($indexUrl),
            e($parentLabel),
            e($currentLabel),
        );
    }

    protected function buildSingleSegmentHeadingHtml(string $indexUrl, string $label): string
    {
        return sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">%s</a></span>',
            e($indexUrl),
            e($label),
        );
    }
}
