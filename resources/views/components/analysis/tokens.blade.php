{{-- Farb-Tokens aller Analyse-Diagramme: Bildschirm (Filament-Variablen, Dark Mode) und Druck (feste Farben). --}}
<style>
        .an-chart { --an-text: rgb(var(--gray-500)); --an-strong: rgb(var(--gray-700)); --an-axis: rgb(var(--gray-300)); --an-grid: rgb(var(--gray-200));
            --an-row: rgba(var(--gray-500), .05); --an-whisker: rgb(var(--gray-500)); --an-box: rgba(var(--primary-500), .14); --an-box-line: rgb(var(--primary-600));
            --an-median: rgb(var(--primary-700)); --an-dot: rgb(var(--primary-600)); --an-dot-low: rgb(var(--warning-600)); --an-dot-stroke: #fff;
            --an-thr: rgb(var(--warning-500)); --an-norm: rgb(var(--gray-400)); --an-w: #2a78d6; --an-m: #eb6834; --an-o: rgb(var(--gray-400));
            --an-b-f: rgba(var(--danger-500), .12); --an-b-a: rgba(var(--warning-500), .14); --an-b-h: rgba(var(--info-500), .12); --an-b-n: rgba(var(--success-500), .08); }
        .dark .an-chart { --an-text: rgb(var(--gray-400)); --an-strong: rgb(var(--gray-200)); --an-axis: rgb(var(--gray-600)); --an-grid: rgb(var(--gray-800));
            --an-row: rgba(255,255,255,.03); --an-whisker: rgb(var(--gray-400)); --an-box: rgba(var(--primary-400), .18); --an-box-line: rgb(var(--primary-400));
            --an-median: rgb(var(--primary-300)); --an-dot: rgb(var(--primary-400)); --an-dot-low: rgb(var(--warning-400)); --an-dot-stroke: rgb(var(--gray-900));
            --an-w: #3987e5; --an-m: #d95926; --an-b-f: rgba(var(--danger-400), .18); --an-b-a: rgba(var(--warning-400), .18); --an-b-n: rgba(var(--success-400), .10); }
        .an-chart.an-print { --an-text: #555; --an-strong: #222; --an-axis: #bbb; --an-grid: #e5e5e5; --an-row: #f7f7f7; --an-whisker: #555;
            --an-box: rgba(37, 99, 235, .14); --an-box-line: #2563eb; --an-median: #1d4ed8; --an-dot: #2563eb; --an-dot-low: #d97706; --an-dot-stroke: #fff;
            --an-thr: #f59e0b; --an-norm: #999; --an-w: #2a78d6; --an-m: #eb6834; --an-o: #999;
            --an-b-f: rgba(239, 68, 68, .14); --an-b-a: rgba(245, 158, 11, .16); --an-b-h: rgba(59, 130, 246, .12); --an-b-n: rgba(34, 197, 94, .10); }
        .an-chart { --an-s-f: rgba(var(--danger-500), .55); --an-s-a: rgba(var(--warning-500), .55); --an-s-h: rgba(var(--info-500), .45); --an-s-n: rgba(var(--success-500), .30);
            --an-c1: #2a78d6; --an-c2: #eb6834; --an-c3: #1baf7a; --an-c4: #eda100; --an-c5: #e87ba4; --an-c6: #008300; }
        .dark .an-chart { --an-s-f: rgba(var(--danger-500), .6); --an-s-a: rgba(var(--warning-500), .6); --an-s-n: rgba(var(--success-500), .35);
            --an-c1: #3987e5; --an-c2: #d95926; }
        .an-chart.an-print { --an-s-f: #f19a9a; --an-s-a: #f8cf7c; --an-s-h: #9cc2f5; --an-s-n: #b6e3c6; }
        /* Tabellen */
        .an-table { width: 100%; border-collapse: collapse; font-size: .875rem; font-variant-numeric: tabular-nums; }
        .an-table th, .an-table td { padding: .35rem .5rem; border-bottom: 1px solid rgb(var(--gray-200)); text-align: right; white-space: nowrap; }
        .an-table th:first-child, .an-table td:first-child { text-align: left; white-space: normal; }
        .an-table thead th { font-weight: 600; color: rgb(var(--gray-600)); background: rgba(var(--gray-500), .06); }
        .an-table tr.is-total td { font-weight: 600; border-top: 2px solid rgb(var(--gray-300)); }
        .an-table .muted { color: rgb(var(--gray-400)); }
        .an-table button.an-group { color: rgb(var(--primary-600)); text-decoration: underline; text-underline-offset: 2px; text-align: left; }
        .dark .an-table th, .dark .an-table td { border-color: rgb(var(--gray-700)); }
        .dark .an-table thead th { color: rgb(var(--gray-300)); background: rgba(255,255,255,.04); }
        .dark .an-table button.an-group { color: rgb(var(--primary-400)); }
        .an-print .an-table { font-size: 9.5pt; }
        .an-print .an-table th, .an-print .an-table td { border-color: #ddd; }
        .an-print .an-table thead th { color: #333; background: #f3f3f3; }
        .an-print .an-table .muted { color: #999; }
        .an-print .an-table tr.is-total td { border-top: 2px solid #999; }
</style>
