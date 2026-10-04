<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root {
            --bar-bg: #1f2937;
            --bar-text: #f9fafb;
            --accent: #2563eb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Times New Roman", Times, serif;
            background: #e5e7eb;
            color: #111;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.65rem 1rem;
            background: var(--bar-bg);
            color: var(--bar-text);
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
        }
        .toolbar h1 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            font-family: "Times New Roman", Times, serif;
        }
        .toolbar .meta {
            font-size: 0.8rem;
            opacity: 0.85;
            margin-top: 0.15rem;
        }
        .toolbar-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        .toolbar a,
        .toolbar button {
            appearance: none;
            border: 0;
            border-radius: 0.35rem;
            padding: 0.45rem 0.85rem;
            font: inherit;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            color: #fff;
            background: var(--accent);
        }
        .toolbar button.secondary,
        .toolbar a.secondary {
            background: #4b5563;
        }
        .frame-wrap {
            height: calc(100vh - 58px);
            background: #fff;
        }
        .frame-wrap iframe {
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }
        @media print {
            .toolbar { display: none !important; }
            .frame-wrap {
                height: auto;
            }
            .frame-wrap iframe {
                height: 100vh;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <h1>{{ $title }}</h1>
            <div class="meta">{{ $filename }}</div>
        </div>
        <div class="toolbar-actions">
            <button type="button" class="secondary" id="print-btn">Print</button>
            <a href="{{ $downloadUrl }}">Download</a>
        </div>
    </div>
    <div class="frame-wrap">
        <iframe
            id="preview-frame"
            title="Stock card preview"
            src="{{ $inlineUrl }}"
        ></iframe>
    </div>
    <script>
        document.getElementById('print-btn')?.addEventListener('click', function () {
            const frame = document.getElementById('preview-frame');
            try {
                if (frame?.contentWindow) {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                    return;
                }
            } catch (e) {
                // Fall back to window.print
            }
            window.print();
        });
    </script>
</body>
</html>
