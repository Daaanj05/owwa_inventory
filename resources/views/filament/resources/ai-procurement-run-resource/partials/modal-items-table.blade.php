@php
    $consumableItems = $consumableItems ?? [];
    $replacementItems = $replacementItems ?? [];
    $defaultTab = count($consumableItems) > 0 ? 'consumables' : 'replacement';
@endphp

<div
    class="owwa-ai-run-modal-items"
    x-data="{ tab: @js($defaultTab) }"
>
    @if (count($consumableItems) === 0 && count($replacementItems) === 0)
        <p class="owwa-ai-run-modal-items-empty">No recommended items in this run.</p>
    @else
        <div class="owwa-ai-run-modal-tabs" role="tablist" aria-label="Recommended item categories">
            <button
                type="button"
                role="tab"
                class="owwa-ai-run-modal-tab"
                x-bind:class="{ 'is-active': tab === 'consumables' }"
                x-bind:aria-selected="tab === 'consumables' ? 'true' : 'false'"
                x-on:click="tab = 'consumables'"
            >
                Consumables ({{ count($consumableItems) }})
            </button>
            <button
                type="button"
                role="tab"
                class="owwa-ai-run-modal-tab"
                x-bind:class="{ 'is-active': tab === 'replacement' }"
                x-bind:aria-selected="tab === 'replacement' ? 'true' : 'false'"
                x-on:click="tab = 'replacement'"
            >
                Semi-expendable ({{ count($replacementItems) }})
            </button>
        </div>

        <div class="owwa-ai-run-modal-tab-panels">
            <div
                class="owwa-ai-run-modal-tab-panel"
                role="tabpanel"
                x-show="tab === 'consumables'"
                x-cloak
            >
                @if (count($consumableItems) === 0)
                    <p class="owwa-ai-run-modal-items-empty">No consumable reorder items in this run.</p>
                @else
                    <div class="owwa-ai-run-modal-items-scroll">
                        <table class="owwa-ai-run-modal-items-table">
                            <thead>
                                <tr>
                                    <th scope="col">Priority</th>
                                    <th scope="col">Item</th>
                                    <th scope="col" class="owwa-ai-run-modal-items-num">Suggested</th>
                                    <th scope="col">Rationale</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($consumableItems as $item)
                                    <tr>
                                        <td>
                                            <span @class([
                                                'owwa-ai-run-priority-badge',
                                                'owwa-ai-run-priority-badge--high' => ($item['priority'] ?? '') === 'High',
                                                'owwa-ai-run-priority-badge--medium' => ($item['priority'] ?? '') === 'Medium',
                                                'owwa-ai-run-priority-badge--low' => ($item['priority'] ?? '') === 'Low',
                                            ])>
                                                {{ $item['priority'] ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="owwa-ai-run-modal-items-item">{{ $item['item_name'] ?? '—' }}</td>
                                        <td class="owwa-ai-run-modal-items-num">{{ $item['suggested_qty'] ?? '—' }}</td>
                                        <td class="owwa-ai-run-modal-items-reason" title="{{ $item['reason'] ?? '' }}">
                                            {{ $item['reason'] ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div
                class="owwa-ai-run-modal-tab-panel"
                role="tabpanel"
                x-show="tab === 'replacement'"
                x-cloak
            >
                @if (count($replacementItems) === 0)
                    <p class="owwa-ai-run-modal-items-empty">No semi-expendable replacement items in this run.</p>
                @else
                    <div class="owwa-ai-run-modal-items-scroll">
                        <table class="owwa-ai-run-modal-items-table">
                            <thead>
                                <tr>
                                    <th scope="col">Priority</th>
                                    <th scope="col">Item</th>
                                    <th scope="col" class="owwa-ai-run-modal-items-num">Suggested</th>
                                    <th scope="col">Rationale</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($replacementItems as $item)
                                    <tr>
                                        <td>
                                            <span @class([
                                                'owwa-ai-run-priority-badge',
                                                'owwa-ai-run-priority-badge--high' => ($item['priority'] ?? '') === 'High',
                                                'owwa-ai-run-priority-badge--medium' => ($item['priority'] ?? '') === 'Medium',
                                                'owwa-ai-run-priority-badge--low' => ($item['priority'] ?? '') === 'Low',
                                            ])>
                                                {{ $item['priority'] ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="owwa-ai-run-modal-items-item">{{ $item['item_name'] ?? '—' }}</td>
                                        <td class="owwa-ai-run-modal-items-num">{{ $item['suggested_qty'] ?? '—' }}</td>
                                        <td class="owwa-ai-run-modal-items-reason" title="{{ $item['reason'] ?? '' }}">
                                            {{ $item['reason'] ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
