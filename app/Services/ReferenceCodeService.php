<?php

namespace App\Services;

use App\Models\ItemCategory;
use App\Models\ReferenceSeries;
use Illuminate\Support\Facades\DB;

class ReferenceCodeService
{
    public function forAcquisition(): string
    {
        return $this->nextCode(ReferenceSeries::typeForAcquisition());
    }

    public function forIssuance(): string
    {
        return $this->nextCode(ReferenceSeries::typeForIssuance());
    }

    public function forTransfer(): string
    {
        return $this->nextCode(ReferenceSeries::typeForTransfer());
    }

    public function forDisposal(): string
    {
        return $this->nextCode(ReferenceSeries::typeForDisposal());
    }

    public function forRequisition(): string
    {
        return $this->nextCode(ReferenceSeries::typeForRequisition());
    }

    public function forProcurementPr(): string
    {
        return $this->forAcquisitionPaperworkPr();
    }

    public function forProcurementPo(): string
    {
        return $this->forAcquisitionPaperworkPo();
    }

    public function forProcurementIar(): string
    {
        return $this->forAcquisitionPaperworkIar();
    }

    public function forAcquisitionPaperworkPr(): string
    {
        return $this->nextCode(ReferenceSeries::typeForAcquisitionPaperworkPr());
    }

    public function forAcquisitionPaperworkPo(): string
    {
        return $this->nextCode(ReferenceSeries::typeForAcquisitionPaperworkPo());
    }

    public function forAcquisitionPaperworkIar(): string
    {
        return $this->nextCode(ReferenceSeries::typeForAcquisitionPaperworkIar());
    }

    public function forItemCode(ItemCategory $category): string
    {
        $type = $this->itemCodeSeriesType($category->getTemplateSlug());

        return $this->nextCode($type);
    }

    public function forPropertyNumber(string $categorySlug): string
    {
        $type = config('inventory.property_number_series.'.$categorySlug);
        if (! is_string($type) || $type === '') {
            throw new \RuntimeException("No property number series configured for category: {$categorySlug}");
        }

        return $this->nextCode($type);
    }

    public function previewItemCodeForCategoryId(?int $categoryId): string
    {
        if ($categoryId === null) {
            return '';
        }

        $category = ItemCategory::find($categoryId);
        if (! $category) {
            return '';
        }

        return $this->previewNext($this->itemCodeSeriesType($category->getTemplateSlug()));
    }

    public function itemCodeSeriesType(string $categorySlug): string
    {
        $type = config('inventory.item_code_series.'.$categorySlug);
        if (! is_string($type) || $type === '') {
            throw new \RuntimeException("No item code series configured for category: {$categorySlug}");
        }

        return $type;
    }

    public function nextCode(string $type): string
    {
        return DB::transaction(function () use ($type): string {
            $series = ReferenceSeries::query()
                ->where('type', $type)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();

            if (! $series) {
                throw new \RuntimeException("Active reference series not found for type: {$type}. Restore the format in System Admin or run the reference series seeder.");
            }

            $this->maybeResetSequence($series);

            $code = $this->expandPattern($series);
            $code = $this->normalizeControlNumberForType($type, $code, $series->next_sequence, $series->reset_period);
            $series->next_sequence++;
            $series->last_generated_at = now()->toDateString();
            $series->save();

            return $code;
        });
    }

    protected function maybeResetSequence(ReferenceSeries $series): void
    {
        $last = $series->last_generated_at;
        $now = now();

        $shouldReset = match ($series->reset_period) {
            ReferenceSeries::RESET_DAILY => $last === null || $last->format('Y-m-d') < $now->format('Y-m-d'),
            ReferenceSeries::RESET_MONTHLY => $last === null || $last->format('Y-m') < $now->format('Y-m'),
            ReferenceSeries::RESET_YEARLY => $last === null || $last->format('Y') < $now->format('Y'),
            default => false,
        };

        if ($shouldReset) {
            $series->next_sequence = 1;
        }
    }

    protected function expandPattern(ReferenceSeries $series): string
    {
        $pattern = $series->pattern;
        $seq = $series->next_sequence;

        $replacements = [
            '{prefix}' => $series->prefix,
            '{Y}' => now()->format('Y'),
            '{m}' => now()->format('m'),
            '{d}' => now()->format('d'),
        ];

        foreach ($replacements as $placeholder => $value) {
            $pattern = str_replace($placeholder, $value, $pattern);
        }

        if (preg_match('/\{seq:(\d+)\}/', $pattern, $m)) {
            $pad = (int) $m[1];
            $pattern = preg_replace('/\{seq:\d+\}/', str_pad((string) $seq, $pad, '0', STR_PAD_LEFT), $pattern, 1);
        } else {
            $pattern = str_replace('{seq}', (string) $seq, $pattern);
        }

        return $pattern;
    }

    public function previewNext(string $type): string
    {
        $series = ReferenceSeries::query()
            ->where('type', $type)
            ->whereNull('archived_at')
            ->first();

        if (! $series) {
            return '';
        }

        $clone = clone $series;
        $this->maybeResetSequence($clone);

        return $this->normalizeControlNumberForType($type, $this->expandPattern($clone), $clone->next_sequence, $clone->reset_period);
    }

    protected function normalizeControlNumberForType(string $type, string $code, int $sequence, string $resetPeriod): string
    {
        if ($this->isMasterDataSeriesType($type)) {
            return $code;
        }

        $normalized = strtoupper(trim($code));
        if (preg_match('/^\d{4}-\d{2}-\d{4}$/', $normalized) === 1) {
            return $normalized;
        }

        $typesRequiringControlFormat = [
            ReferenceSeries::TYPE_REQUISITION,
            ReferenceSeries::TYPE_ISSUANCE,
            ReferenceSeries::TYPE_TRANSFER,
            ReferenceSeries::TYPE_DISPOSAL,
            ReferenceSeries::TYPE_ACQUISITION,
        ];

        if (! in_array($type, $typesRequiringControlFormat, true)) {
            return $code;
        }

        $year = now()->format('Y');
        $middle = $resetPeriod === ReferenceSeries::RESET_MONTHLY ? now()->format('m') : '01';
        $serial = str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

        return "{$year}-{$middle}-{$serial}";
    }

    protected function isMasterDataSeriesType(string $type): bool
    {
        return in_array($type, [
            ReferenceSeries::TYPE_ITEM_CODE_CONSUMABLES,
            ReferenceSeries::TYPE_ITEM_CODE_PPE,
            ReferenceSeries::TYPE_ITEM_CODE_SEMI,
            ReferenceSeries::TYPE_PROPERTY_NUMBER_PPE,
            ReferenceSeries::TYPE_PROPERTY_NUMBER_SEMI,
        ], true);
    }
}
