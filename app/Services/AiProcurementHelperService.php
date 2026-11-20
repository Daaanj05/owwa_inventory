<?php

namespace App\Services;

use App\Models\AiProcurementItem;
use App\Models\AiProcurementRun;
use App\Support\StockCoverPhrase;

class AiProcurementHelperService
{
    public const TYPE_EXPLAIN_HIGH = 'explain_high';

    public const TYPE_NEXT_PR_PACKAGE = 'next_pr_package';

    public const TYPE_SEMI_EXPENDABLE_FIRST = 'semi_expendable_first';

    public const NEXT_STEP_AWAITING_UPDATE = 'Awaiting for update (Still usable or needs replacement)';

    /**
     * Persist all deterministic helper sections after a Generate run (no Ollama).
     */
    public function persistDeterministicHelpers(AiProcurementRun $run): void
    {
        $run->loadMissing('items');
        $facts = $this->buildFacts($run);
        $now = now()->toIso8601String();

        $helpers = [
            self::TYPE_EXPLAIN_HIGH => [
                'title' => 'Why these are flagged',
                'body' => $this->deterministicFallback(self::TYPE_EXPLAIN_HIGH, $facts),
                'generated_at' => $now,
            ],
            self::TYPE_NEXT_PR_PACKAGE => [
                'title' => 'What to buy next',
                'body' => $this->deterministicFallback(self::TYPE_NEXT_PR_PACKAGE, $facts),
                'generated_at' => $now,
            ],
            self::TYPE_SEMI_EXPENDABLE_FIRST => [
                'title' => 'Replace or Update Semi-Expendable Items',
                'body' => $this->deterministicFallback(self::TYPE_SEMI_EXPENDABLE_FIRST, $facts),
                'generated_at' => $now,
            ],
        ];

        $run->update(['helpers' => $helpers]);
    }

    public function explainHighItem(object $item): string
    {
        return $this->whyExplanationForItem($item);
    }

    public function usagePhrase(?float $avgMonthlyUsage): string
    {
        if ($avgMonthlyUsage === null) {
            return '';
        }

        return number_format($avgMonthlyUsage, 1).' units/month';
    }

    public function lifeRemainingPhrase(?int $daysRemaining): string
    {
        if ($daysRemaining === null) {
            return '';
        }

        if ($daysRemaining < 0) {
            return 'past EUL';
        }

        if ($daysRemaining === 0) {
            return 'expires today';
        }

        $months = max(1, (int) round($daysRemaining / 30));

        return '~'.$months.' '.($months === 1 ? 'month' : 'months').' left';
    }

    public function nextStepForReplacement(object $item): string
    {
        $actionCode = (string) ($item->replacement_action ?? '');
        $actionLabel = trim((string) ($item->reason ?? ''));

        return match ($actionCode) {
            SemiExpendableEulAnalyticsService::ACTION_ISSUE_FROM_STOCK,
            SemiExpendableEulAnalyticsService::ACTION_PURCHASE => $actionLabel !== '' ? $actionLabel : $actionCode,
            default => self::NEXT_STEP_AWAITING_UPDATE,
        };
    }

    /**
     * @return array{high_lines: string, consumable_lines: string, replacement_lines: string, purchase_lines: string}
     */
    protected function buildFacts(AiProcurementRun $run): array
    {
        $consumables = $run->items->where('section', '!=', 'replacement')->values();
        $replacements = $run->items->where('section', 'replacement')->values();

        $highLines = $consumables
            ->where('priority', 'High')
            ->map(function (AiProcurementItem $item): string {
                $cover = StockCoverPhrase::fromMonths((float) ($item->months_cover ?? 0));
                $cause = $this->whyExplanationForItem($item);

                return sprintf(
                    '- **%s** — %s — stock %s → buy %s · %s',
                    $item->item_name,
                    $cause,
                    $item->current_stock ?? '—',
                    $item->suggested_qty_min ?? '—',
                    $cover,
                );
            })
            ->implode("\n");

        $consumableLines = $consumables
            ->map(function (AiProcurementItem $item): string {
                $cover = StockCoverPhrase::fromMonths((float) ($item->months_cover ?? 0));

                return sprintf(
                    '- **%s** (%s): stock %s → buy %s · %s',
                    $item->item_name,
                    $item->priority ?? '—',
                    $item->current_stock ?? '—',
                    $item->suggested_qty_min ?? '—',
                    $cover,
                );
            })
            ->implode("\n");

        $replacementLines = $replacements
            ->map(function (AiProcurementItem $item): string {
                $status = trim((string) ($item->eul_status ?: '—'));
                $property = $item->property_number ?: 'no property number';
                $next = $this->nextStepForReplacement($item);
                $lifeLeft = $this->lifeRemainingPhrase(
                    $item->eul_days_remaining !== null ? (int) $item->eul_days_remaining : null
                );
                $eulMeta = trim(implode(' · ', array_filter([
                    filled($item->estimated_useful_life) ? 'EUL '.$item->estimated_useful_life : null,
                    $lifeLeft !== '' ? $lifeLeft : null,
                ])));

                return sprintf(
                    '- **%s** (%s)%s — %s → %s',
                    $item->item_name,
                    $property,
                    $eulMeta !== '' ? ' · '.$eulMeta : '',
                    $status,
                    $next,
                );
            })
            ->implode("\n");

        $purchaseLines = $replacements
            ->filter(fn (AiProcurementItem $item): bool => (bool) $item->include_in_request)
            ->map(fn (AiProcurementItem $item): string => sprintf(
                '- **%s** (%s)',
                $item->item_name,
                $item->property_number ?: 'no property number',
            ))
            ->implode("\n");

        return [
            'high_lines' => $highLines !== '' ? $highLines : 'none',
            'consumable_lines' => $consumableLines !== '' ? $consumableLines : 'none',
            'replacement_lines' => $replacementLines !== '' ? $replacementLines : 'none',
            'purchase_lines' => $purchaseLines !== '' ? $purchaseLines : 'none',
        ];
    }

    /**
     * Full High-item explanation for UI and helper bodies.
     */
    public function whyExplanationForItem(object $item): string
    {
        $usage = $this->usagePhrase(
            isset($item->avg_monthly_usage) && $item->avg_monthly_usage !== null
                ? (float) $item->avg_monthly_usage
                : null
        );
        $parts = $this->whyCauseParts($item);

        $hasCoverClause = false;
        foreach ($parts as $index => $part) {
            if (str_contains($part, 'cover at current use')) {
                $hasCoverClause = true;
                if ($usage !== '') {
                    $parts[$index] = $part.' of '.$usage;
                }
            }
        }

        if (! $hasCoverClause && $usage !== '') {
            $parts[] = $usage;
        }

        if ($parts === []) {
            return 'Needs reorder attention';
        }

        return ucfirst(implode(' · ', $parts));
    }

    /**
     * @return list<string>
     */
    protected function whyCauseParts(object $item): array
    {
        $parts = [];
        $reason = (string) ($item->reason ?? '');

        if (preg_match('/Stock\s+(\d+)\s*\(reorder\s+(\d+)\)/i', $reason, $matches) === 1) {
            if ((int) $matches[1] <= (int) $matches[2]) {
                $parts[] = 'below reorder (frequent requests keep stock low)';
            }
        }

        $coverMonths = (float) ($item->months_cover ?? 0);
        if ($coverMonths > 0 && $coverMonths < 1) {
            $parts[] = 'only '.StockCoverPhrase::fromMonths($coverMonths).' cover at current use';
        } elseif ($coverMonths >= 1 && $coverMonths < 3) {
            $parts[] = StockCoverPhrase::fromMonths($coverMonths).' cover at current use';
        }

        if ($parts === [] && isset($item->avg_monthly_usage) && $item->avg_monthly_usage !== null && (float) $item->avg_monthly_usage > 0) {
            $parts[] = 'ongoing monthly use needs a restock';
        }

        return $parts;
    }

    /**
     * @param  array{high_lines: string, consumable_lines: string, replacement_lines: string, purchase_lines: string}  $facts
     */
    protected function deterministicFallback(string $helperType, array $facts): string
    {
        return match ($helperType) {
            self::TYPE_EXPLAIN_HIGH => $facts['high_lines'] === 'none'
                ? 'No High-priority consumables are in this recommendation.'
                : $facts['high_lines'],
            self::TYPE_NEXT_PR_PACKAGE => $facts['consumable_lines'] === 'none' && $facts['purchase_lines'] === 'none'
                ? 'Nothing to buy in this recommendation.'
                : trim(
                    ($facts['consumable_lines'] !== 'none' ? $facts['consumable_lines'] : '')
                    .($facts['purchase_lines'] !== 'none'
                        ? ($facts['consumable_lines'] !== 'none' ? "\n\n" : '')."Semi-expendable purchases:\n{$facts['purchase_lines']}"
                        : '')
                ),
            self::TYPE_SEMI_EXPENDABLE_FIRST => $facts['replacement_lines'] === 'none'
                ? 'No semi-expendable units need Issue from stock, Purchase, or an update this cycle.'
                : $facts['replacement_lines'],
            default => 'Recommendation helper is ready.',
        };
    }
}
