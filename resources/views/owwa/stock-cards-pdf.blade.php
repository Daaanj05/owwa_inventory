@include('owwa.partials.card-print-styles')
@include('owwa.partials.pdf-computer-generated-footer')

@foreach ($cards as $card)
    @include(match ($card['slug']) {
        'ppe' => 'owwa.partials.pc-card',
        'semi_expendable' => 'owwa.partials.annex-a1-card',
        default => 'owwa.partials.sc-card',
    }, ['card' => $card])

    @if (! $loop->last)
        <div class="owwa-pdf-page-break"></div>
    @endif
@endforeach
