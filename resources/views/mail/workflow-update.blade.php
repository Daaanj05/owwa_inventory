<x-mail::message :logo-message="$message ?? null">
# {{ $title }}

Hello {{ $recipient->name }},

{{ $body }}

@php
    $context = $context ?? null;
    $requestedItems = $requestedItems ?? [];
@endphp

@if ($requestedItems !== [])
<x-mail::panel>
**Requested items:**
@foreach ($requestedItems as $line)
- {{ $line['name'] }} — {{ $line['quantity'] }}@if (filled($line['unit'] ?? null)) {{ $line['unit'] }}@endif
@endforeach
</x-mail::panel>
@endif

@if ($context && $context->hasPanelDetails())
<x-mail::panel>
@if (filled($context->employeeName))
**Employee:** {{ $context->employeeName }}

@endif
@if ($context->employees !== [])
**Employees:**
@foreach ($context->employees as $employee)
- {{ $employee['name'] }} ({{ $employee['reference'] }})
@endforeach

@endif
**Office:** {{ $context->officeName ?: '—' }}

**Department:** {{ $context->departmentName ?: '—' }}
</x-mail::panel>
@endif

<x-mail::button :url="url('/login')">
Sign in to review
</x-mail::button>

Thanks,<br>
{{ config('owwa_mail.brand_name') }}
</x-mail::message>
