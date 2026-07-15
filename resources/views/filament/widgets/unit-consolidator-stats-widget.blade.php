@php
    $pollingInterval = $this->getPollingInterval();
@endphp

<x-filament-widgets::widget
    :attributes="
        (new \Illuminate\View\ComponentAttributeBag)
            ->merge([
                'wire:poll.'.$pollingInterval => $pollingInterval ? true : null,
            ], escape: false)
            ->class([
                'fi-wi-stats-overview',
            ])
    "
>
    {{ $this->content }}

    <x-filament-actions::modals />
</x-filament-widgets::widget>
