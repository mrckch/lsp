<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>@yield('title', 'LSP – Lese-Test')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#f8fafc; color:#0f172a; line-height:1.5; }
        .wrap { max-width: 900px; margin: 0 auto; padding: 1rem; }
        .card { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); padding:1.5rem; margin-bottom:1rem; }
        h1 { margin-top:0; font-size:1.5rem; }
        h2 { font-size:1.15rem; }
        .timer { position:sticky; top:0; background:#1e3a8a; color:#fff; padding:0.5rem 1rem; border-radius:0 0 8px 8px; font-weight:700; text-align:center; font-size:1.1rem; }
        .timer.warning { background:#b91c1c; }
        .question { display:flex; align-items:center; gap:0.5rem; padding:0.65rem 0.75rem; border-bottom:1px solid #e2e8f0; }
        .question:nth-child(odd) { background:#f8fafc; }
        .q-text { flex:1; font-size:1rem; }
        .q-actions { display:flex; gap:0.4rem; }
        .btn { background:#2563eb; color:#fff; border:0; padding:0.6rem 1.2rem; border-radius:6px; font-size:1rem; font-weight:600; cursor:pointer; }
        .btn:hover { background:#1d4ed8; }
        .btn-r { background:#16a34a; }
        .btn-r:hover { background:#15803d; }
        .btn-f { background:#dc2626; }
        .btn-f:hover { background:#b91c1c; }
        .btn-r.active, .btn-f.active { box-shadow:0 0 0 3px rgba(0,0,0,0.2); }
        .btn-block { display:block; width:100%; padding:1rem; font-size:1.1rem; }
        input[type=text] { font-size:1.5rem; padding:0.75rem; width:100%; border:2px solid #cbd5e1; border-radius:6px; text-align:center; letter-spacing:0.2em; text-transform:uppercase; }
        .err { background:#fee2e2; color:#991b1b; padding:0.75rem; border-radius:6px; margin-bottom:1rem; }
        .ok { background:#dcfce7; color:#166534; padding:0.75rem; border-radius:6px; margin-bottom:1rem; }
        .label { font-size:0.875rem; color:#64748b; }
        .badge { display:inline-block; background:#e0f2fe; color:#075985; padding:0.15rem 0.5rem; border-radius:9999px; font-size:0.85rem; }
    </style>
</head>
<body>
    <div class="wrap">@yield('content')</div>
    @stack('scripts')
</body>
</html>
