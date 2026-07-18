@php
    /** @var list<array{id: int, reference: string, requested_by: string, office: string, quantity_requested: int, regional_stock: int}> $rows */
    $rows = $rows ?? [];
    $path = is_callable($getStatePath ?? null)
        ? $getStatePath()
        : (isset($field) && method_exists($field, 'getStatePath') ? $field->getStatePath() : 'selected_requisitions');
@endphp

<div
    wire:ignore.self
    class="owwa-zero-stock-req-picker"
    x-data="{
        state: $wire.$entangle(@js($path), true),
        isChecked(id) {
            const current = Array.isArray(this.state) ? this.state : [];

            return current.map(String).includes(String(id));
        },
        toggle(id) {
            const current = Array.isArray(this.state) ? [...this.state] : [];
            const key = String(id);
            const index = current.findIndex((value) => String(value) === key);

            if (index >= 0) {
                current.splice(index, 1);
            } else {
                current.push(Number.isNaN(Number(id)) ? id : Number(id));
            }

            this.state = current;
        },
    }"
>
    @if ($rows === [])
        <p class="owwa-zero-stock-req-empty">No zero-stock unit consolidator requisitions are available right now.</p>
    @else
        <div class="owwa-zero-stock-req-table-wrap">
            <table class="owwa-zero-stock-req-table">
                <thead>
                    <tr>
                        <th class="owwa-zero-stock-req-check" scope="col">
                            <span class="sr-only">Select</span>
                        </th>
                        <th scope="col">Requisition</th>
                        <th scope="col">Requested by</th>
                        <th scope="col">Office</th>
                        <th scope="col" class="owwa-zero-stock-req-num">Qty requested</th>
                        <th scope="col" class="owwa-zero-stock-req-num">Regional stock</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr
                            class="owwa-zero-stock-req-row"
                            x-bind:class="{ 'is-selected': isChecked(@js($row['id'])) }"
                            x-on:click="toggle(@js($row['id']))"
                        >
                            <td class="owwa-zero-stock-req-check">
                                <input
                                    type="checkbox"
                                    class="owwa-zero-stock-req-checkbox"
                                    x-bind:checked="isChecked(@js($row['id']))"
                                    x-on:click.stop="toggle(@js($row['id']))"
                                    x-on:keydown.space.prevent.stop="toggle(@js($row['id']))"
                                    aria-label="Select {{ $row['reference'] }}"
                                />
                            </td>
                            <td>{{ $row['reference'] }}</td>
                            <td>{{ $row['requested_by'] }}</td>
                            <td>{{ $row['office'] }}</td>
                            <td class="owwa-zero-stock-req-num">{{ number_format($row['quantity_requested']) }}</td>
                            <td class="owwa-zero-stock-req-num">{{ number_format($row['regional_stock']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
