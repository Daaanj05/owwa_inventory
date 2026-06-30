@php
    $items = $items ?? [];
@endphp

<div class="owwa-ai-run-modal-items">
    @if (count($items) === 0)
        <p class="owwa-ai-run-modal-items-empty">No recommended items in this run.</p>
    @else
        <div class="owwa-ai-run-modal-items-scroll">
            <table class="owwa-ai-run-modal-items-table">
                <thead>
                    <tr>
                        <th scope="col">Priority</th>
                        <th scope="col">Item</th>
                        <th scope="col">Office</th>
                        <th scope="col" class="owwa-ai-run-modal-items-num">Suggested</th>
                        <th scope="col">Rationale</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
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
                            <td>{{ $item['office_name'] ?? '—' }}</td>
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
