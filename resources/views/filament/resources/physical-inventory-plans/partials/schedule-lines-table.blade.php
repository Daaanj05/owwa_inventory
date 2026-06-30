@php
    use App\Models\PhysicalInventoryPlan;
    use App\Models\PhysicalInventoryPlanLine;
    use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
    use App\Services\InventoryPlanLineStatusService;

    /** @var PhysicalInventoryPlan $record */
    $record->loadMissing(['lines.office', 'lines.itemCategory', 'lines.physicalCountSession']);
    $lines = $record->lines->sortBy('planned_date')->values();
    $statusService = app(InventoryPlanLineStatusService::class);
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
                        <tr wire:key="plan-line-{{ $line->id }}">
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
                                    <x-filament::link
                                        tag="button"
                                        type="button"
                                        color="primary"
                                        size="xs"
                                        wire:click="startPlanLineCount({{ $line->id }})"
                                    >
                                        Start count
                                    </x-filament::link>
                                @elseif (! $line->physicalCountSession?->isComplete())
                                    <x-filament::link
                                        :href="PhysicalCountSessionResource::getUrl('view', ['record' => $line->physical_count_session_id])"
                                        color="primary"
                                        size="xs"
                                    >
                                        Continue
                                    </x-filament::link>
                                @else
                                    <x-filament::link
                                        :href="PhysicalCountSessionResource::getUrl('view', ['record' => $line->physical_count_session_id])"
                                        color="gray"
                                        size="xs"
                                    >
                                        View count
                                    </x-filament::link>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
