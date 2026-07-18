@php
    $generatedAt = $generatedAt ?? now();
    $generatedOn = $generatedAt instanceof \DateTimeInterface
        ? \Illuminate\Support\Carbon::instance($generatedAt)->timezone(config('app.timezone'))->format('F j, Y')
        : \Illuminate\Support\Carbon::parse((string) $generatedAt)->timezone(config('app.timezone'))->format('F j, Y');
@endphp
<p class="owwa-pdf-disclaimer">This is computer generated on {{ $generatedOn }}.</p>
