<x-mail::message :logo-message="$message ?? null">
# Your sign-in code

Hello {{ $user->name }},

Use this code to sign in to **{{ $user->email }}**.

<div style="margin: 28px 0; text-align: center;">
<p style="margin: 0 0 8px; font-size: 14px; color: #4b5563;">Sign-in code:</p>
<p style="margin: 0; font-size: 36px; font-weight: 700; letter-spacing: 0.35em; color: #111827;">{{ $code }}</p>
<p style="margin: 12px 0 0; font-size: 13px; color: #6b7280;">This code expires in {{ $expiresLabel }}.</p>
</div>

Return to the sign-in page and enter this code.

If you did not try to sign in, you can ignore this email.

Thanks,<br>
{{ config('owwa_mail.brand_name') }}
</x-mail::message>
