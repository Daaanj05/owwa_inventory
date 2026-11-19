@props(['url', 'logoMessage' => null])
@php
    $logoHeight = (int) config('owwa_mail.logo_height', 64);
    $owwaLogo = \App\Support\OwwaMailLogo::src($logoMessage, 'owwa');
    $bagongLogo = \App\Support\OwwaMailLogo::src($logoMessage, 'bagong_pilipinas');
    $bagongWidth = $logoHeight;
    $bagongPath = public_path((string) config('owwa_mail.logos.bagong_pilipinas'));
    $bagongSize = is_file($bagongPath) ? getimagesize($bagongPath) : false;
    if (is_array($bagongSize) && ($bagongSize[1] ?? 0) > 0) {
        $bagongWidth = (int) round($logoHeight * ($bagongSize[0] / $bagongSize[1]));
    }
    $navyBright = config('owwa_mail.colors.navy_bright');
    $crimson = config('owwa_mail.colors.crimson');
    $gradient = config('owwa_mail.header_gradient');
@endphp
<tr>
<td class="header" style="padding: 0; border: 0;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center" style="background-color: {{ $navyBright }}; background: {{ $gradient }}; padding: 28px 24px; text-align: center; border-bottom: 3px solid {{ $crimson }};">
<table cellpadding="0" cellspacing="0" role="presentation" align="center">
<tr>
<td style="padding: 0 10px; vertical-align: middle;">
<img src="{{ $owwaLogo }}" alt="OWWA Region IV-A" width="{{ $logoHeight }}" height="{{ $logoHeight }}" style="display: block; width: {{ $logoHeight }}px; height: {{ $logoHeight }}px; max-width: {{ $logoHeight }}px; border: 0;">
</td>
<td style="padding: 0 10px; vertical-align: middle;">
<img src="{{ $bagongLogo }}" alt="Bagong Pilipinas" width="{{ $bagongWidth }}" height="{{ $logoHeight }}" style="display: block; width: {{ $bagongWidth }}px; height: {{ $logoHeight }}px; max-width: {{ $bagongWidth }}px; border: 0;">
</td>
</tr>
</table>
<p style="color: #ffffff; font-size: 18px; font-weight: 700; margin: 16px 0 6px; text-align: center; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
{{ config('owwa_mail.brand_name') }}
</p>
<p style="color: rgba(255, 255, 255, 0.85); font-size: 12px; line-height: 1.4; margin: 0; text-align: center; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
{{ config('owwa_mail.tagline') }}
</p>
</td>
</tr>
</table>
</td>
</tr>
