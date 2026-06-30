<?php

namespace App\Services;

use App\Models\PhysicalCountSession;
use Illuminate\Validation\ValidationException;

class PhysicalCountCompletionService
{
    /**
     * @return array{can_complete: bool, missing_fields: array<int, string>, has_shortages: bool}
     */
    public function evaluate(PhysicalCountSession $session): array
    {
        $session->loadMissing('lines');
        $summary = $session->countSummary();
        $missingFields = $session->missingCompletionFields();
        $hasShortages = $summary['shortages'] > 0;
        $needsBookList = $session->supportsQrScanning() && ! $session->hasBookListLoaded();

        return [
            'can_complete' => $missingFields === [] && ! $hasShortages && ! $needsBookList,
            'missing_fields' => $missingFields,
            'has_shortages' => $hasShortages,
            'needs_book_list' => $needsBookList,
        ];
    }

    public function finishCounting(PhysicalCountSession $session): PhysicalCountSession
    {
        if (! $session->supportsQrScanning()) {
            throw ValidationException::withMessages([
                'status' => 'Finish counting is only available for PPE and semi-expendable sessions.',
            ]);
        }

        $session->update([
            'status' => PhysicalCountSession::STATUS_INCOMPLETE,
        ]);

        return $session->fresh();
    }

    public function markComplete(PhysicalCountSession $session): PhysicalCountSession
    {
        $evaluation = $this->evaluate($session);

        if (! $evaluation['can_complete']) {
            $messages = [];

            if ($evaluation['missing_fields'] !== []) {
                $messages[] = 'Missing: '.implode(', ', $evaluation['missing_fields']).'.';
            }

            if ($evaluation['has_shortages']) {
                $messages[] = 'Resolve shortage lines before marking complete.';
            }

            if ($evaluation['needs_book_list'] ?? false) {
                $messages[] = 'Load expected assets on desktop before marking complete.';
            }

            throw ValidationException::withMessages([
                'status' => implode(' ', $messages),
            ]);
        }

        $session->update([
            'status' => PhysicalCountSession::STATUS_COMPLETE,
            'completed_at' => now(),
        ]);

        return $session->fresh();
    }

    public function markIncomplete(PhysicalCountSession $session): PhysicalCountSession
    {
        $session->update([
            'status' => PhysicalCountSession::STATUS_INCOMPLETE,
            'completed_at' => null,
        ]);

        return $session->fresh();
    }
}
