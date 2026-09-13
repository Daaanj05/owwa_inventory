<?php

namespace App\Livewire;

use App\Filament\Pages\ProcurementAnalytics;
use App\Models\AiProcurementRun;
use App\Support\AiProcurementSummaryRestore;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

        $analyticsUrl = ProcurementAnalytics::getUrl().'#procurement-summary';

        if ($run->status === 'failed') {
            Notification::make()
                ->title('AI recommendation failed')
                ->body($run->error_message ?: 'The recommendation could not be completed.')
                ->danger()
                ->seconds(10)
                ->actions([
                    Action::make('viewResult')
                        ->label('View the result')
                        ->url($analyticsUrl),
                ])
                ->send();

            return;
        }

        Notification::make()
            ->title('AI recommendation ready')
            ->body('Your procurement recommendation is ready on Procurement Analytics.')
            ->success()
            ->seconds(10)
            ->actions([
                Action::make('viewResult')
                    ->label('View the result')
                    ->url($analyticsUrl),
            ])
            ->send();
    }

    public function getAnalyticsUrlProperty(): string
    {
        return ProcurementAnalytics::getUrl().'#procurement-summary';
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
