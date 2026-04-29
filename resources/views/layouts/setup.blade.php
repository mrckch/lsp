<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'LSP – Einrichtung')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f3f4f6;
            color: #111827;
            min-height: 100vh;
            line-height: 1.5;
        }
        .container { max-width: 720px; margin: 4rem auto; padding: 0 1rem; }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 4px 16px rgba(0,0,0,0.06);
            padding: 2.5rem;
        }
        h1 { margin-top: 0; font-size: 1.75rem; }
        h2 { margin-top: 2rem; font-size: 1.15rem; color: #374151; }
        label { display: block; font-weight: 600; font-size: 0.9rem; margin-bottom: 0.25rem; color: #374151; }
        input[type=text], input[type=email], input[type=password] {
            width: 100%; padding: 0.6rem 0.75rem;
            border: 1px solid #d1d5db; border-radius: 6px;
            font-size: 1rem; font-family: inherit;
        }
        input:focus { outline: 2px solid #2563eb; border-color: #2563eb; }
        .field { margin-bottom: 1.25rem; }
        .help { font-size: 0.85rem; color: #6b7280; margin-top: 0.25rem; }
        .errors { background: #fee2e2; color: #991b1b; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .errors ul { margin: 0.25rem 0 0 1.25rem; padding: 0; }
        .btn {
            background: #2563eb; color: #fff; border: 0;
            padding: 0.75rem 1.5rem; border-radius: 6px; font-size: 1rem; font-weight: 600;
            cursor: pointer;
        }
        .btn:hover { background: #1d4ed8; }
        .btn-block { display: block; width: 100%; }
        .checkbox-row { display: flex; gap: 0.5rem; align-items: flex-start; }
        .checkbox-row input { margin-top: 0.25rem; }
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.95rem; }
        .alert-warn { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
        .alert-danger { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }
        .recovery-key {
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            font-size: 1.1rem;
            background: #f9fafb;
            border: 2px dashed #d1d5db;
            padding: 1.25rem;
            border-radius: 8px;
            word-break: break-all;
            text-align: center;
            margin: 1.5rem 0;
            user-select: all;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>LSP – Lese-Screening-Portal</h1>
            @if ($errors->any())
                <div class="errors">
                    <strong>Bitte korrigieren Sie folgende Eingaben:</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @yield('content')
        </div>
    </div>
</body>
</html>
