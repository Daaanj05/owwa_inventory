<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — OWWA Inventory</title>
    <link rel="stylesheet" href="{{ asset('css/filament/admin/owwa-theme.css') }}">
    <style>
        .owwa-error-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem;
            background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 45%, #fef2f2 100%);
        }

        .owwa-error-card {
            width: 100%;
            max-width: 32rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
            text-align: center;
        }

        .owwa-error-code {
            font-size: 3rem;
            font-weight: 800;
            line-height: 1;
            color: #1d4ed8;
            margin-bottom: 0.75rem;
        }

        .owwa-error-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.75rem;
        }

        .owwa-error-message {
            color: #4b5563;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .owwa-error-hint {
            font-size: 0.875rem;
            color: #6b7280;
            line-height: 1.5;
            margin-bottom: 1.5rem;
        }

        .owwa-error-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
        }

        .owwa-error-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.65rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            text-decoration: none;
        }

        .owwa-error-btn-primary {
            background: linear-gradient(90deg, #1d4ed8, #dc2626);
            color: #fff;
        }

        .owwa-error-btn-secondary {
            background: #f3f4f6;
            color: #111827;
            border: 1px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="owwa-error-page">
        <div class="owwa-error-card">
            @yield('content')
        </div>
    </div>
</body>
</html>
