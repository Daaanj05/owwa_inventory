<x-mail::message :logo-message="$message ?? null">
# {{ $requisition->reference_code ?? 'Requisition' }} rejected

Hello {{ $recipient->name }},

Your requisition was rejected.

<x-mail::panel>
**Reference:** {{ $requisition->reference_code ?? '—' }}

**Office:** {{ ($context->officeName ?? null) ?: ($requisition->office?->name ?? '—') }}

**Department:** {{ ($context->departmentName ?? null) ?: ($requisition->department?->name ?: '—') }}

@if (($context->employees ?? []) !== [])
**Employees:**
@foreach ($context->employees as $employee)
- {{ $employee['name'] }} ({{ $employee['reference'] }})
@endforeach
@elseif (filled($context->employeeName ?? null))
**Employee:** {{ $context->employeeName }}
@endif

@if (filled($requisition->remarks))
**Reason:** {{ $requisition->remarks }}
@endif
</x-mail::panel>

<x-mail::button :url="url('/login')">
Sign in to review
</x-mail::button>

Thanks,<br>
{{ config('owwa_mail.brand_name') }}
</x-mail::message>
