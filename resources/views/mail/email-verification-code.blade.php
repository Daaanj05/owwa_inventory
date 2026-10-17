<x-mail::message :logo-message="$message ?? null">
# Your verification code

Hello {{ $user->name }},

Use this code to continue setting up **{{ $user->email }}**.

<div style="margin: 28px 0; text-align: center;">
<p style="margin: 0 0 8px; font-size: 14px; color: #4b5563;">Verification code:</p>
<p style="margin: 0; font-size: 36px; font-weight: 700; letter-spacing: 0.35em; color: #111827;">{{ $code }}</p>
<p style="margin: 12px 0 0; font-size: 13px; color: #6b7280;">This code expires in {{ $expiresInMinutes }} minutes.</p>
</div>

Return to the account setup page, enter this code, then choose your password.

If you did not request this code, contact your administrator.

Thanks,<br>
{{ config('owwa_mail.brand_name') }}
</x-mail::message>
