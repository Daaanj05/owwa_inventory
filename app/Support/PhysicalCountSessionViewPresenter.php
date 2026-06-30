<?php

namespace App\Support;

use App\Models\PhysicalCountSession;
use App\Services\PhysicalCountCompletionService;

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
