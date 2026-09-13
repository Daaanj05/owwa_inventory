<?php

namespace App\Livewire;

use App\Filament\Pages\ProcurementAnalytics;
use App\Models\AiProcurementRun;
use App\Support\AiProcurementSummaryRestore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class AiProcurementBusyChip extends Component
{
    public ?int $processingRunId = null;

    public ?int $completedRunId = null;

    public function mount(): void
    {
        $this->refreshProcessingRun();
    }

    #[On('ai-procurement-busy-refresh')]
    public function refreshProcessingRun(): void
    {
        $userId = Auth::id();
        if ($userId === null) {
            $this->processingRunId = null;

            return;
        }

        $previousProcessingId = $this->processingRunId;

        $run = AiProcurementRun::query()
            ->where('created_by', $userId)
            ->where('status', 'processing')
            ->latest('id')
            ->first();

        $this->processingRunId = $run?->id;

        if ($previousProcessingId !== null && $this->processingRunId === null) {
            $completed = AiProcurementRun::query()
                ->whereKey($previousProcessingId)
                ->where('created_by', $userId)
                ->whereIn('status', ['draft', 'failed'])
                ->first();

            if ($completed !== null && $this->completedRunId !== $completed->id) {
                $this->completedRunId = $completed->id;
                $this->notifyRunCompleted($completed);
            }
        }
    }

    protected function notifyRunCompleted(AiProcurementRun $run): void
    {
        if (! AiProcurementSummaryRestore::claimSessionToast($run->id)) {
            return;
        }

        if (Auth::id() !== null) {
            AiProcurementSummaryRestore::remember((int) Auth::id(), (int) $run->id);
        }

        $analyticsUrl = ProcurementAnalytics::resultUrl();

        if ($run->status === 'failed') {
            $this->js(AiProcurementSummaryRestore::browserAnnounceScript([
                'title' => 'AI recommendation failed',
                'body' => $run->error_message ?: 'The recommendation could not be completed.',
                'danger' => true,
                'seconds' => 10,
                'actionLabel' => 'View the result',
                'actionUrl' => $analyticsUrl,
            ]));

            return;
        }

        $this->js(AiProcurementSummaryRestore::browserAnnounceScript([
            'title' => 'AI recommendation ready',
            'body' => 'Your procurement recommendation is ready on Procurement Analytics.',
            'seconds' => 10,
            'actionLabel' => 'View the result',
            'actionUrl' => $analyticsUrl,
        ]));
    }

    public function getAnalyticsUrlProperty(): string
    {
        return ProcurementAnalytics::resultUrl();
    }

    public function getShouldShowChipProperty(): bool
    {
        // Visibility on Analytics is gated client-side (pathname) so Livewire
        // poll requests do not incorrectly show this chip next to the page-local UI.
        return $this->processingRunId !== null;
    }

    public function render(): View
    {
        return view('livewire.ai-procurement-busy-chip');
    }
}
