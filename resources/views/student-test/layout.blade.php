<!DOCTYPE html>
<html lang="de" class="@yield('html_class')">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>@yield('title', 'LSP – Lese-Test')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        :root { --bar-h: 3.75rem; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#f1f5f9; color:#0f172a; line-height:1.5; -webkit-text-size-adjust:100%; }
        .wrap { max-width: 900px; margin: 0 auto; padding: 1rem; padding-left: max(1rem, env(safe-area-inset-left)); padding-right: max(1rem, env(safe-area-inset-right)); }
        .card { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); padding:1.5rem; margin-bottom:1rem; }
        h1 { margin-top:0; font-size:1.5rem; }
        h2 { font-size:1.15rem; }
        .btn { background:#2563eb; color:#fff; border:0; padding:0.6rem 1.2rem; border-radius:8px; font-size:1rem; font-weight:600; cursor:pointer; touch-action:manipulation; }
        .btn:hover { background:#1d4ed8; }
        .btn:disabled { opacity:.5; cursor:default; }
        .btn-secondary { background:#e2e8f0; color:#0f172a; }
        .btn-secondary:hover { background:#cbd5e1; }
        .btn-r { background:#16a34a; }
        .btn-r:hover { background:#15803d; }
        .btn-f { background:#dc2626; }
        .btn-f:hover { background:#b91c1c; }
        .btn-block { display:block; width:100%; padding:1rem; font-size:1.1rem; }
        input[type=text] { font-size:1.5rem; padding:0.75rem; width:100%; border:2px solid #cbd5e1; border-radius:8px; text-align:center; letter-spacing:0.2em; text-transform:uppercase; }
        .err { background:#fee2e2; color:#991b1b; padding:0.75rem; border-radius:6px; margin-bottom:1rem; }
        .ok { background:#dcfce7; color:#166534; padding:0.75rem; border-radius:6px; margin-bottom:1rem; }
        .label { font-size:0.875rem; color:#64748b; }
        .badge { display:inline-block; background:#e0f2fe; color:#075985; padding:0.15rem 0.5rem; border-radius:9999px; font-size:0.85rem; }
        .footer-link { text-align:center; font-size:0.85rem; color:#64748b; margin-top:2rem; }
        .footer-link a { color:#475569; }

        /* ── Fokus-Ansicht (Übung + Test): eine Aussage im Mittelpunkt ───────── */
        /* Kein CSS-Scroll-Snap: WebKit (iPad/iPhone) rastet sonst nach jeder Layoutänderung
           auf die zuletzt von Hand angesteuerte Karte zurück. Einrasten übernimmt partials/focus-script. */
        html.focus { scroll-padding-top: var(--bar-h); }
        html.focus .wrap { padding-top:0; }
        .bar { position:sticky; top:0; z-index:10; min-height:var(--bar-h); margin:0 -1rem; padding:0.5rem 1rem;
               background:#1e3a8a; color:#fff; display:flex; flex-wrap:wrap; align-items:center; gap:0.25rem 1rem;
               border-radius:0 0 12px 12px; box-shadow:0 2px 8px rgba(15,23,42,.2); }
        .bar.warning { background:#b91c1c; }
        .bar .timer { font-weight:700; font-size:1.25rem; font-variant-numeric:tabular-nums; }
        .bar .timer-label { font-size:0.8rem; opacity:.8; margin-right:.25rem; font-weight:400; }
        .bar .progress { font-size:0.95rem; opacity:.9; font-variant-numeric:tabular-nums; }
        .bar .auto { margin-left:auto; display:flex; align-items:center; gap:0.4rem; font-size:0.9rem; cursor:pointer; user-select:none; }
        .bar .auto input { width:1.2rem; height:1.2rem; accent-color:#fff; }
        .bar .short { display:none; }
        @media (max-width: 480px) {
            .bar { gap:0.25rem 0.75rem; }
            .bar .long { display:none; }
            .bar .short { display:inline; }
        }
        .save-status { flex-basis:100%; background:#fef3c7; color:#92400e; padding:0.35rem 0.75rem; border-radius:6px; text-align:center; font-weight:600; font-size:0.9rem; }

        .q-card { position:relative;
                  min-height:calc(100vh - var(--bar-h) - 2rem); min-height:calc(100dvh - var(--bar-h) - 2rem);
                  margin:1rem 0; padding:1.5rem; background:#fff; border-radius:16px; box-shadow:0 1px 4px rgba(15,23,42,.08);
                  display:flex; flex-direction:column; justify-content:center; gap:1.5rem;
                  transition: filter .25s, opacity .25s, transform .25s, box-shadow .25s; }
        .q-card .q-num { font-size:0.9rem; color:#64748b; text-align:center; }
        .q-card .q-text { margin:0; font-size:clamp(1.3rem, 4.8vw, 1.9rem); line-height:1.4; font-weight:500; text-align:center;
                          overflow-wrap:anywhere; hyphens:auto; }
        .q-card .q-actions { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; }
        .q-card .q-actions .btn { min-height:3.5rem; font-size:1.2rem; border-radius:12px; }
        .q-card .q-actions .btn.active { box-shadow:0 0 0 4px #fff, 0 0 0 7px #0f172a; }
        .q-card.answered .q-actions .btn:not(.active) { opacity:.35; }
        .q-card.unsaved { box-shadow: inset 6px 0 0 #f59e0b, 0 1px 4px rgba(15,23,42,.08); }
        .q-card .feedback { text-align:center; font-weight:700; font-size:1.1rem; min-height:1.6rem; }
        .q-card .feedback.good { color:#15803d; }
        .q-card .feedback.bad { color:#b91c1c; }
        .q-final { text-align:center; }
        .q-final h2 { margin:0; font-size:1.4rem; }
        .q-spacer { display:none; }
        body.locked .q-card.question .btn { pointer-events:none; opacity:.35; }

        /* Tablet quer: drei Aussagen sichtbar, die mittlere scharf */
        @media (orientation: landscape) and (min-width: 768px) and (min-height: 500px) {
            .q-card { min-height:calc((100vh - var(--bar-h)) / 3 - 1rem); min-height:calc((100dvh - var(--bar-h)) / 3 - 1rem);
                      margin:0.5rem 0; flex-direction:row; align-items:center; gap:1.5rem; padding:1rem 1.5rem; }
            .q-card .q-num { min-width:3.5rem; }
            .q-card .q-body { flex:1; }
            .q-card .q-text { text-align:left; font-size:clamp(1.2rem, 2.4vw, 1.6rem); }
            .q-card .q-actions { width:18rem; flex:none; }
            .q-card .feedback { text-align:left; }
            .q-card .feedback:empty { display:none; }
            .q-final { flex-direction:column; }
            .q-card:not(.is-active) { filter:blur(2px); opacity:.45; transform:scale(.97); cursor:pointer; }
            .q-card:not(.is-active) .q-actions, .q-card:not(.is-active) form { pointer-events:none; }
            .q-card.is-active { box-shadow:0 0 0 3px #1e3a8a, 0 8px 24px rgba(15,23,42,.15); }
            .q-spacer { display:block; height:calc((100vh - var(--bar-h)) / 3); height:calc((100dvh - var(--bar-h)) / 3); }
        }
        @media (prefers-reduced-motion: reduce) { .q-card { transition:none; } }
    </style>
</head>
<body class="@yield('body_class')">
    <div class="wrap">@yield('content')</div>
    @stack('scripts')
</body>
</html>
