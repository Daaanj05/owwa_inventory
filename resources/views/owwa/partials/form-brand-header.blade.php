@php
    $owwaLogo = public_path(config('owwa_mail.logos.owwa', 'images/owwa-form-logo.png'));
    $bagongPilipinasLogo = public_path(config('owwa_mail.logos.bagong_pilipinas', 'images/bagong-pilipinas-form-logo.png'));
    $hasOwwaLogo = is_readable($owwaLogo);
    $hasBagongPilipinasLogo = is_readable($bagongPilipinasLogo);
    $appendix = $appendix ?? '';
    $title = $title ?? '';
@endphp

<table class="owwa-form-meta">
    <tr>
        <td></td>
        <td class="owwa-form-appendix">{{ $appendix }}</td>
    </tr>
</table>

<table class="owwa-form-brand">
    <tr>
        <td class="owwa-form-brand-logo--left">
            @if ($hasBagongPilipinasLogo)
                <img src="{{ $bagongPilipinasLogo }}" alt="Bagong Pilipinas" class="owwa-form-brand-logo">
            @endif
        </td>
        <td class="owwa-form-brand-center">
            <p class="owwa-form-agency">
                Republic of the Philippines<br>
                OVERSEAS WORKERS WELFARE ADMINISTRATION
            </p>
            <p class="owwa-form-title">{{ $title }}</p>
        </td>
        <td class="owwa-form-brand-logo--right">
            @if ($hasOwwaLogo)
                <img src="{{ $owwaLogo }}" alt="OWWA" class="owwa-form-brand-logo">
            @endif
        </td>
    </tr>
</table>
