<?php

namespace App\Support;

use App\Models\PhysicalCountSession;
use App\Services\PhysicalCountCompletionService;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class PhysicalCountSessionViewPresenter
{
    /**
     * @return array{expected: int, scanned: int, shortages: int, overages: int, matched: int, scan_only: bool}
     */
    public static function summary(PhysicalCountSession $session): array
    {
        $session->loadMissing('lines');

        return $session->countSummary();
    }

    public static function progressPercent(PhysicalCountSession $session): int
    {
        $summary = self::summary($session);
        $expected = $summary['expected'];

        if ($expected === 0) {
            return 0;
        }

        return (int) min(100, round(($summary['scanned'] / $expected) * 100));
    }

    /**
     * @return array<int, array{label: string, shortLabel: string, description: string, state: string, url: ?string, step: int}>
     */
    public static function workflowSteps(PhysicalCountSession $session): array
    {
        if (! $session->supportsQrScanning()) {
            return [];
        }

        $session->loadMissing('lines');
        $summary = self::summary($session);
        $evaluation = app(PhysicalCountCompletionService::class)->evaluate($session);

        $scanState = $session->isComplete() ? 'done' : ($summary['scanned'] > 0 ? 'active' : 'pending');
        $bookState = $session->hasBookListLoaded() ? 'done' : ($summary['scanned'] > 0 ? 'active' : 'pending');
        $completeState = $session->isComplete() ? 'done' : ($evaluation['can_complete'] ? 'active' : 'pending');
        $exportState = $session->isComplete() ? 'done' : 'pending';

        $scanUrl = $session->isComplete()
            ? null
            : \App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource::getUrl('scan', ['record' => $session]);

        $exportUrl = $session->isComplete()
            ? route('owwa.export.physical-count', $session)
            : null;

        return [
            [
                'step' => 1,
                'label' => 'Scan tags',
                'shortLabel' => 'Scan',
                'description' => ($summary['scan_only'] ?? false)
                    ? "{$summary['scanned']} tag(s) scanned"
                    : 'Scan property QR tags on mobile',
                'state' => $scanState,
                'url' => $scanUrl,
            ],
            [
                'step' => 2,
                'label' => 'Load book',
                'shortLabel' => 'Book',
                'description' => $session->hasBookListLoaded()
                    ? 'Book list loaded'
                    : 'Load expected assets on desktop',
                'state' => $bookState,
                'url' => null,
            ],
            [
                'step' => 3,
                'label' => 'Complete',
                'shortLabel' => 'Complete',
                'description' => $session->isComplete()
                    ? 'Session completed'
                    : ($evaluation['can_complete'] ? 'Ready to mark complete' : 'Resolve checklist items'),
                'state' => $completeState,
                'url' => null,
            ],
            [
                'step' => 4,
                'label' => 'Export',
                'shortLabel' => 'Export',
                'description' => $session->isComplete()
                    ? 'Download OWWA form'
                    : 'Available after completion',
                'state' => $exportState,
                'url' => $exportUrl,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function missingForCompleteLines(PhysicalCountSession $session): array
    {
        $missing = $session->missingCompletionFields();

        $items = $missing;
        if (! $session->hasBookListLoaded()) {
            $items[] = 'load expected assets (book list)';
        }

        if ($items === []) {
            return ['Ready to mark complete'];
        }

        return array_map(
            fn (string $line): string => Str::title($line),
            $items,
        );
    }

    public static function missingForCompleteHtml(PhysicalCountSession $session): HtmlString
    {
        $lines = self::missingForCompleteLines($session);

        return new HtmlString(implode('<br>', array_map(
            fn (string $line): string => e($line),
            $lines,
        )));
    }

    public static function qrWorkflowStepsHtml(): HtmlString
    {
        $steps = [
            ['Load Expected Assets', 'pulls issued property numbers for the selected office (book balance, on-hand starts at 0).'],
            ['Print QR Labels', 'from issuances or bulk from this session.'],
            ['Scan With Phone', 'each tag found increments on-hand count.'],
            ['Review Shortages/Overages', 'recorded on the OWWA export after completion.'],
        ];

        $body = collect($steps)
            ->values()
            ->map(fn (array $step, int $index): string => e(($index + 1).'. '.$step[0].' — '.$step[1]))
            ->implode('<br>');

        return new HtmlString(
            '<p><strong>After You Save This Session:</strong></p>'
            .'<p>'.$body.'</p>'
            .'<p>'.e('Count lines are added automatically; you do not need to enter items manually on this screen.').'</p>'
        );
    }

    /**
     * @return array<int, array{
     *     item_id: int,
     *     item_name: string,
     *     tag_count: int,
     *     balance_per_card: int,
     *     on_hand_count: int,
     *     variance: int,
     *     property_numbers: array<int, string>
     * }>
     */
    public static function linesGroupedByItem(PhysicalCountSession $session): array
    {
        $session->loadMissing(['lines.item']);

        $groups = [];

        foreach ($session->lines as $line) {
            $itemId = (int) ($line->item_id ?? 0);
            $key = $itemId > 0 ? (string) $itemId : 'line:'.$line->id;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'item_id' => $itemId,
                    'item_name' => $line->item?->name ?? $line->article ?? 'Unknown item',
                    'tag_count' => 0,
                    'balance_per_card' => 0,
                    'on_hand_count' => 0,
                    'variance' => 0,
                    'property_numbers' => [],
                ];
            }

            $groups[$key]['tag_count']++;
            $groups[$key]['balance_per_card'] += (int) $line->balance_per_card;
            $groups[$key]['on_hand_count'] += (int) $line->on_hand_count;
            $groups[$key]['variance'] += $line->shortageOverageQuantity();

            if (filled($line->property_number)) {
                $groups[$key]['property_numbers'][] = (string) $line->property_number;
            }
        }

        usort($groups, function (array $a, array $b): int {
            if ($a['variance'] < 0 && $b['variance'] >= 0) {
                return -1;
            }

            if ($a['variance'] >= 0 && $b['variance'] < 0) {
                return 1;
            }

            return strcasecmp($a['item_name'], $b['item_name']);
        });

        return array_values($groups);
    }

    /**
     * @return array{session: PhysicalCountSession, summary: array, progressPercent: int, workflowSteps: array}
     */
    public static function forSession(PhysicalCountSession $session): array
    {
        $session->loadMissing(['office', 'lines.item']);

        return [
            'session' => $session,
            'summary' => self::summary($session),
            'progressPercent' => self::progressPercent($session),
            'workflowSteps' => self::workflowSteps($session),
        ];
    }
}
