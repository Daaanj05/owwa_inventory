<?php

namespace App\Filament\Resources\PhysicalCountSessions\Pages;

use App\Filament\Resources\PhysicalCountSessions\Concerns\HasPhysicalCountWizardBreadcrumbs;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Services\PhysicalCountCompletionService;
use App\Services\PhysicalCountScanService;
use App\Support\PhysicalCountScanOutcome;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

class ScanPhysicalCountSession extends Page
{
    use HasPhysicalCountWizardBreadcrumbs;
    use InteractsWithRecord;

    protected static string $resource = PhysicalCountSessionResource::class;

    protected static ?string $title = 'Scan physical count';

    protected static ?string $navigationLabel = 'Scan';

    protected string $view = 'filament.resources.physical-count-sessions.pages.scan-physical-count-session';

    #[Locked]
    public ?string $lastScanMessage = null;

    #[Locked]
    public string $lastScanTone = 'info';

    public string $manualCode = '';

    public bool $showTallyView = false;

    public bool $showFinishSummary = false;

    public string $tallyFilter = 'all';

    /** @var array<int, array{time: string, property_number: string, result: string, message: string}> */
    public array $recentScans = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless($this->getRecord()->supportsQrScanning(), 404);

        $this->syncActiveCategoryFromSession($this->getRecord());
    }

    public function getHeading(): string|Htmlable
    {
        /** @var PhysicalCountSession $session */
        $session = $this->getRecord();

        $lastSegment = match (true) {
            $this->showFinishSummary => 'Finish',
            $this->showTallyView => 'Tally',
            default => 'Scan',
        };

        return $this->physicalCountSessionBreadcrumbHtml($session, [
            ['label' => $lastSegment],
        ]);
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->office?->name;
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return ['owwa-physical-count-scan-page'];
    }

    public function resolveScan(string $code): void
    {
        $result = app(PhysicalCountScanService::class)->resolve(
            $this->getRecord(),
            $code,
            auth()->id(),
        );

        $this->lastScanMessage = $result->message;
        $this->lastScanTone = match ($result->outcome) {
            PhysicalCountScanOutcome::Found => 'success',
            PhysicalCountScanOutcome::Overage => 'warning',
            PhysicalCountScanOutcome::Duplicate => 'warning',
            PhysicalCountScanOutcome::NotFound => 'danger',
        };

        array_unshift($this->recentScans, [
            'time' => now()->format('H:i:s'),
            'property_number' => app(PhysicalCountScanService::class)->normalizePropertyNumber($code),
            'result' => $result->outcome->value,
            'message' => (string) $result->message,
        ]);
        $this->recentScans = array_slice($this->recentScans, 0, 10);

        $this->dispatch('physical-count-scan-processed', tone: $this->lastScanTone);
    }

    public function submitManualCode(): void
    {
        if (blank($this->manualCode)) {
            return;
        }

        $this->resolveScan($this->manualCode);
        $this->manualCode = '';
    }

    public function openTallyView(?string $filter = null): void
    {
        $this->showTallyView = true;
        $this->showFinishSummary = false;

        if ($filter !== null) {
            $this->tallyFilter = $filter;
        }
    }

    public function closeTallyView(): void
    {
        $this->showTallyView = false;
        $this->showFinishSummary = false;
    }

    public function setTallyFilter(string $filter): void
    {
        $this->tallyFilter = $filter;
    }

    public function finishCounting(): void
    {
        /** @var PhysicalCountSession $session */
        $session = $this->getRecord();

        app(PhysicalCountCompletionService::class)->finishCounting($session);

        $this->showFinishSummary = true;
        $this->showTallyView = false;

        Notification::make()
            ->title('Counting finished')
            ->body('Review the tally, then complete signatories on desktop.')
            ->success()
            ->send();
    }

    /**
     * @return array{expected: int, scanned: int, shortages: int, overages: int, matched: int}
     */
    public function countSummary(): array
    {
        /** @var PhysicalCountSession $session */
        $session = $this->getRecord()->fresh(['lines']);

        return $session->countSummary();
    }

    public function tallyProgressPercent(): int
    {
        $summary = $this->countSummary();
        $expected = $summary['expected'];

        if ($expected === 0) {
            return 0;
        }

        return (int) min(100, round(($summary['scanned'] / $expected) * 100));
    }

    /**
     * @return Collection<int, PhysicalCountLine>
     */
    public function tallyLines(): Collection
    {
        /** @var PhysicalCountSession $session */
        $session = $this->getRecord()->fresh(['lines.item']);

        return $session->lines
            ->sortBy(fn (PhysicalCountLine $line): int => match (true) {
                $line->shortageOverageQuantity() < 0 => 0,
                $line->shortageOverageQuantity() > 0 => 2,
                default => 1,
            })
            ->filter(function (PhysicalCountLine $line): bool {
                $variance = $line->shortageOverageQuantity();

                return match ($this->tallyFilter) {
                    'missing' => $variance < 0,
                    'found' => $variance === 0,
                    'overage' => $variance > 0,
                    default => true,
                };
            })
            ->values();
    }

    public function desktopViewUrl(): string
    {
        return PhysicalCountSessionResource::viewModalUrl($this->getRecord());
    }
}
