@php
    use App\Models\PhysicalInventoryPlan;
    use App\Models\PhysicalInventoryPlanLine;
    use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
    use App\Services\InventoryPlanLineStatusService;

    /** @var PhysicalInventoryPlan $record */
    $record->loadMissing(['lines.office', 'lines.itemCategory', 'lines.physicalCountSession']);
    $statusService = app(InventoryPlanLineStatusService::class);
    $lines = $record->lines
        ->sortBy(function (PhysicalInventoryPlanLine $line) use ($statusService): array {
            $status = $statusService->statusForLine($line);
            $overdueRank = $status === PhysicalInventoryPlanLine::STATUS_OVERDUE ? 0 : 1;

            return [$overdueRank, $line->planned_date?->timestamp ?? PHP_INT_MAX, $line->id];
        })
        ->values();
@endphp

<div class="owwa-inventory-plan-schedule">
    <h3 class="owwa-inventory-plan-schedule-title">Schedule</h3>

    @if ($lines->isEmpty())
        <p class="owwa-inventory-plan-schedule-empty">No schedule lines yet. Edit the schedule to add offices and dates.</p>
    @else
        <div class="owwa-inventory-plan-schedule-scroll">
            <table class="owwa-inventory-plan-schedule-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Office</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th class="owwa-inventory-plan-schedule-actions">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        @php
                            $status = $statusService->statusForLine($line);
                            $statusLabel = match ($status) {
                                PhysicalInventoryPlanLine::STATUS_PENDING => 'Pending',
                                PhysicalInventoryPlanLine::STATUS_IN_PROGRESS => 'In progress',
                                PhysicalInventoryPlanLine::STATUS_COMPLETE => 'Complete',
                                PhysicalInventoryPlanLine::STATUS_OVERDUE => 'Overdue',
                                default => ucfirst($status),
                            };
                            $statusClass = match ($status) {
                                PhysicalInventoryPlanLine::STATUS_COMPLETE => 'owwa-inventory-plan-status--complete',
                                PhysicalInventoryPlanLine::STATUS_IN_PROGRESS => 'owwa-inventory-plan-status--progress',
                                PhysicalInventoryPlanLine::STATUS_OVERDUE => 'owwa-inventory-plan-status--overdue',
                                default => 'owwa-inventory-plan-status--pending',
                            };
                        @endphp
                        <tr wire:key="plan-line-{{ $line->id }}" @class(['owwa-inventory-plan-schedule-row--overdue' => $status === PhysicalInventoryPlanLine::STATUS_OVERDUE])>
                            <td class="whitespace-nowrap">{{ $line->planned_date?->format('M j, Y') ?? '—' }}</td>
                            <td>{{ $line->office?->name ?? '—' }}</td>
                            <td>{{ $line->itemCategory?->name ?? '—' }}</td>
                            <td>
                                <span class="owwa-inventory-plan-status {{ $statusClass }}">
                                    {{ $statusLabel }}
                                </span>
                            </td>
                            <td class="owwa-inventory-plan-schedule-actions whitespace-nowrap">
                                @if ($line->physical_count_session_id === null)
                                    <button
                                        type="button"
                                        wire:click="startPlanLineCount({{ $line->id }})"
                                        class="owwa-inventory-plan-line-btn owwa-inventory-plan-line-btn--primary"
                                    >
                                        Start count
                                    </button>
                                @elseif (! $line->physicalCountSession?->isComplete())
                                    <a
                                        href="{{ PhysicalCountSessionResource::getUrl('view', ['record' => $line->physical_count_session_id]) }}"
                                        class="owwa-inventory-plan-line-btn owwa-inventory-plan-line-btn--primary"
                                    >
                                        Continue
                                    </a>
                                @else
                                    <a
                                        href="{{ PhysicalCountSessionResource::getUrl('view', ['record' => $line->physical_count_session_id]) }}"
                                        class="owwa-inventory-plan-line-btn owwa-inventory-plan-line-btn--gray"
                                    >
                                        View count
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
