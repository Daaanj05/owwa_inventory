<x-mail::message>
# {{ $requisition->reference_code ?? 'Requisition' }} rejected

Hello {{ $recipient->name }},

Your requisition was rejected.

<x-mail::panel>
**Reference:** {{ $requisition->reference_code ?? '—' }}

**Office:** {{ $requisition->office?->name ?? '—' }}

@if (filled($requisition->remarks))
**Reason:** {{ $requisition->remarks }}
@endif
</x-mail::panel>

<x-mail::button :url="url('/admin/login')">
Sign in to review
</x-mail::button>

Thanks,<br>
{{ config('owwa_mail.brand_name') }}
</x-mail::message>
