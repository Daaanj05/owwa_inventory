<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} — OWWA</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        h1 { font-size: 1.25rem; margin-bottom: 0.5rem; }
        p { color: #444; }
        ul { padding-left: 1.25rem; }
        li { margin: 0.5rem 0; }
        a { color: #1d4ed8; }
        .back { margin-top: 2rem; }
    </style>
</head>
<body>
    <h1>{{ $heading }}</h1>
    <p>Each link downloads one Excel file. Use your browser’s back button after each download, or open links in a new tab.</p>
    <ul>
        @foreach ($links as $link)
            <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
        @endforeach
    </ul>
    <p class="back"><a href="{{ $backUrl }}">Return to list</a></p>
</body>
</html>
