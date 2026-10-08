<?php

namespace App\Filament\Pages;

use App\Filament\Resources\UserLogs\UserLogResource;
use App\Services\SystemHealthService;
use App\Services\UserSessionAuditService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use UnitEnum;

class SystemHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?string $navigationLabel = 'System health';

    protected static ?string $title = 'System health';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'system-health';

    protected string $view = 'filament.pages.system-health';

    /**
     * @var array{
     *     checks: list<array{key: string, label: string, status: string, message: string, evidence: string, detail: string, href: ?string, metric: mixed}>,
     *     capacity: array<string, mixed>,
     *     history: array<string, mixed>,
     *     online?: array<string, mixed>
     * }
     */
    public array $report = [
        'checks' => [],
        'capacity' => [],
        'history' => [],
        'online' => [],
    ];

    public string $auditLogsUrl = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSystemAdmin() ?? false;
    }

    public function mount(SystemHealthService $health): void
    {
        $this->auditLogsUrl = UserLogResource::getUrl('index', panel: 'system-admin');
        $this->refreshReport($health);
    }

    public function refreshReport(?SystemHealthService $health = null): void
    {
        app(UserSessionAuditService::class)->closeStaleSessions();

        $health ??= app(SystemHealthService::class);
        $this->report = $health->report();
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getHeading(): string|Htmlable
    {
        return new HtmlString(
            '<span class="owwa-wizard-title" role="list">'
            .'<span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">'
            .'<span class="owwa-wizard-step-icon owwa-wizard-step-icon--health" aria-hidden="true"></span>'
            .'<span class="owwa-wizard-step-label">System health</span>'
            .'</span>'
            .'</span>'
        );
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Live status and who is online. Session counts are not a load-test proof.';
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return ['owwa-system-health-page'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('captureSnapshot')
                ->label('Capture snapshot')
                ->icon(Heroicon::OutlinedCamera)
                ->color('gray')
                ->action(function (): void {
                    app(SystemHealthService::class)->captureSnapshot();
                    $this->refreshReport();

                    Notification::make()
                        ->title('Health snapshot captured')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function viewStatusOverviewAction(): Action
    {
        return Action::make('viewStatusOverview')
            ->modalWidth(Width::FourExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading('Who is online')
            ->modalContent(function (): HtmlString {
                $this->refreshReport();

                $checks = $this->report['checks'] ?? [];
                $online = $this->report['online'] ?? app(SystemHealthService::class)->onlinePeopleOverview();

                $failCount = collect($checks)->where('status', 'fail')->count();
                $warnCount = collect($checks)->where('status', 'warn')->count();
                $statusLabel = match (true) {
                    $failCount > 0 => 'System unhealthy',
                    $warnCount > 0 => 'Needs attention',
                    default => 'System healthy',
                };

                return new HtmlString(view('filament.pages.partials.system-health-status-modal', [
                    'overview' => [
                        'status_label' => $statusLabel,
                        'active_window_minutes' => (int) ($online['active_window_minutes'] ?? 15),
                        'active' => $online['active'] ?? [],
                        'idle' => $online['idle'] ?? [],
                        'by_role' => $online['by_role'] ?? [],
                        'by_portal' => $online['by_portal'] ?? [],
                    ],
                ])->render());
            })
            ->extraModalFooterActions([
                Action::make('openLoginAuditLogs')
                    ->label('Open login history')
                    ->url(fn (): string => $this->auditLogsUrl)
                    ->color('gray')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare),
            ]);
    }
}
