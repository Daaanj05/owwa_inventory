@props(['url'])
@php
    $logoHeight = (int) config('owwa_mail.logo_height', 64);
    $owwaLogo = \Illuminate\Support\Facades\URL::to(config('owwa_mail.logos.owwa'));
    $bagongLogo = \Illuminate\Support\Facades\URL::to(config('owwa_mail.logos.bagong_pilipinas'));
    $navyBright = config('owwa_mail.colors.navy_bright');
    $crimson = config('owwa_mail.colors.crimson');
    $gradient = config('owwa_mail.header_gradient');
@endphp
<tr>
<td class="header" style="padding: 0; border: 0;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center" style="background-color: {{ $navyBright }}; background: {{ $gradient }}; padding: 28px 24px; text-align: center; border-bottom: 3px solid {{ $crimson }};">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<table cellpadding="0" cellspacing="0" role="presentation" align="center">
<tr>
<td style="padding: 0 10px; vertical-align: middle;">
<img src="{{ $owwaLogo }}" alt="OWWA Region IV-A" height="{{ $logoHeight }}" style="display: block; height: {{ $logoHeight }}px; max-height: {{ $logoHeight }}px; width: auto; border: 0;">
</td>
<td style="padding: 0 10px; vertical-align: middle;">
<img src="{{ $bagongLogo }}" alt="Bagong Pilipinas" height="{{ $logoHeight }}" style="display: block; height: {{ $logoHeight }}px; max-height: {{ $logoHeight }}px; width: auto; border: 0;">
</td>
</tr>
</table>
<p style="color: #ffffff; font-size: 18px; font-weight: 700; margin: 16px 0 6px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
{{ config('owwa_mail.brand_name') }}
</p>
<p style="color: rgba(255, 255, 255, 0.85); font-size: 12px; line-height: 1.4; margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
{{ config('owwa_mail.tagline') }}
</p>
</a>
</td>
</tr>
</table>
</td>
</tr>
