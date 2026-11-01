@php
    /** @var array<string, mixed> $about */
    $systemName = (string) ($about['system_name'] ?? 'OWWA IV-A CALABARZON Inventory System');
    $office = (string) ($about['office'] ?? '');
    $projectType = (string) ($about['project_type'] ?? 'Capstone Project');
    $institution = (string) ($about['institution'] ?? '');
    $program = (string) ($about['program'] ?? '');
    $year = (string) ($about['year'] ?? '');
    $adviser = (string) ($about['adviser'] ?? '');
    /** @var list<string> $developers */
    $developers = array_values(array_filter($about['developers'] ?? []));

    $programLine = collect([$institution, $program, $year])
        ->filter(fn (string $part): bool => $part !== '')
        ->implode(' · ');
@endphp

<div class="owwa-about owwa-about--modal">
    <header class="owwa-about-hero">
        <div class="owwa-about-logos" aria-hidden="true">
            <span class="owwa-about-logo owwa-about-logo--owwa"></span>
            <span class="owwa-about-logo owwa-about-logo--bagong"></span>
        </div>

        <p class="owwa-about-badge">{{ $projectType }}</p>
        <h2 class="owwa-about-title">{{ $systemName }}</h2>
        @if ($office !== '')
            <p class="owwa-about-office">{{ $office }}</p>
        @endif
    </header>

    <p class="owwa-about-summary">
        An inventory system with procurement analytics for OWWA Regional Welfare Office IV-A.
        It records acquisitions, issuances, requisitions, physical counts, transfers, disposals,
        and property QR tags in one place.
    </p>

    @if ($programLine !== '' || $adviser !== '')
        <p class="owwa-about-meta-line">
            @if ($programLine !== '')
                {{ $programLine }}
            @endif
            @if ($programLine !== '' && $adviser !== '')
                <span class="owwa-about-meta-sep">·</span>
            @endif
            @if ($adviser !== '')
                Adviser: {{ $adviser }}
            @endif
        </p>
    @endif

    @if ($developers !== [])
        <div class="owwa-about-developers-block">
            <p class="owwa-about-section-title">Developed by</p>
            <ul class="owwa-about-developers">
                @foreach ($developers as $developer)
                    <li>{{ $developer }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
