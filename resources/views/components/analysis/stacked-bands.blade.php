{{--
    100-%-Balken je Gruppe mit den Förderbereichen als Segmenten (Anzahl im Segment, wenn Platz ist),
    Legende mit Gesamtzahlen. groups/bands wie AnalysisReport::groupedDistribution().
--}}
@props([
    'groups' => [],
    'total' => null,
    'bands' => [],
    'print' => false,
    'drilldown' => false,
])
@php
    // Reihenfolge: schwerster Bereich links
    $segments = collect($bands)->values()->all();
    $rows = $total !== null && count($groups) > 1 ? [...$groups, $total] : $groups;
    $W = 800; $padL = 170; $padR = 64; $top = 8; $rowH = 40; $barH = 26;
    $plotW = $W - $padL - $padR;
    $H = $top + count($rows) * $rowH + 26;
    $pct = fn ($c, $n) => $n > 0 ? round($c / $n * 100) : 0;
    $totalN = array_sum(array_column($bands, 'count'));
@endphp

<div {{ $attributes->class(['an-chart', 'an-print' => $print]) }}>
    <x-analysis.tokens />
    <style>
        .an-stack text { fill: var(--an-text); font-size: 14px; }
        .an-stack text.an-label { fill: var(--an-strong); font-size: 15px; }
        .an-stack text.seg-count { font-size: 13px; font-weight: 600; fill: var(--an-strong); }
        .an-stack .seg { stroke: #fff; stroke-width: 1.5; }
        .dark .an-stack .seg { stroke: rgb(var(--gray-900)); }
        .an-stack .seg.sev-foerderbedarf, .an-stack-legend .sev-foerderbedarf { fill: var(--an-s-f); }
        .an-stack .seg.sev-auffaellig, .an-stack-legend .sev-auffaellig { fill: var(--an-s-a); }
        .an-stack .seg.sev-hinweis, .an-stack-legend .sev-hinweis { fill: var(--an-s-h); }
        .an-stack .seg.sev-none, .an-stack-legend .sev-none { fill: var(--an-s-n); }
        .an-stack .grid { stroke: var(--an-grid); }
        .an-stack .total-sep { stroke: var(--an-axis); stroke-width: 1.5; }
        .an-stack .an-link { cursor: pointer; }
        .an-stack .an-link:hover text.an-label { text-decoration: underline; }
        .an-stack-legend { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; font-size: .875rem; margin-bottom: .5rem; color: var(--an-strong); }
        .an-stack-legend > span { display: inline-flex; align-items: center; gap: .4rem; }
    </style>

    <div class="an-stack-legend">
        @foreach($segments as $band)
            <span>
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><rect class="sev-{{ $band['severity'] }}" x="0.5" y="0.5" width="13" height="13" rx="3" /></svg>
                {{ $band['label'] }}
                ({{ $band['from'] === null ? '< '.($band['to'] + 1) : ($band['to'] === null ? '≥ '.$band['from'] : $band['from'].'–'.$band['to']) }}):
                <strong>{{ $band['count'] }}</strong> SuS <span style="opacity:.7;">({{ $pct($band['count'], $totalN) }} %)</span>
            </span>
        @endforeach
    </div>

    <svg class="an-stack" viewBox="0 0 {{ $W }} {{ $H }}" width="100%" role="img"
         aria-label="Anteile der Förderbereiche je Gruppe: {{ collect($rows)->map(fn ($g) => trim($g['label'].' '.($g['sublabel'] ?? '')).' '.collect($segments)->map(fn ($b) => $b['label'].' '.$pct($g['band_counts'][$b['severity']] ?? 0, $g['n']).' %')->implode(', '))->implode('; ') }}"
         style="display:block;">
        @foreach([0, 25, 50, 75, 100] as $t)
            @php $gx = $padL + $plotW * $t / 100; @endphp
            <line class="grid" x1="{{ $gx }}" x2="{{ $gx }}" y1="{{ $top }}" y2="{{ $H - 24 }}" />
            <text x="{{ $gx }}" y="{{ $H - 6 }}" text-anchor="middle">{{ $t }} %</text>
        @endforeach

        @foreach($rows as $i => $g)
            @php
                $y = $top + $i * $rowH + ($rowH - $barH) / 2;
                $isTotal = $g['key'] === 'total';
                $label = trim($g['label'].(($g['sublabel'] ?? null) ? ' · '.$g['sublabel'] : ''));
                $x0 = $padL;
            @endphp
            @if($isTotal)
                <line class="total-sep" x1="0" x2="{{ $W }}" y1="{{ $top + $i * $rowH }}" y2="{{ $top + $i * $rowH }}" />
            @endif
            <g @if($drilldown && ! $print && ! $isTotal) class="an-link" wire:click="mountAction('groupStudents', { group: @js($g['key']) })" @endif>
                <title>{{ $label }}{{ $drilldown && ! $print && ! $isTotal ? ' – Schülerliste öffnen' : '' }}</title>
                <rect x="0" y="{{ $top + $i * $rowH }}" width="{{ $padL - 8 }}" height="{{ $rowH }}" fill="transparent" />
                <text class="an-label" x="8" y="{{ $y + $barH / 2 + 5 }}" @if($isTotal) font-weight="600" @endif>{{ \Illuminate\Support\Str::limit($label, 20) }}</text>
            </g>
            @foreach($segments as $band)
                @php
                    $c = $g['band_counts'][$band['severity']] ?? 0;
                    $w = $g['n'] > 0 ? $plotW * $c / $g['n'] : 0;
                @endphp
                @if($w > 0)
                    <rect class="seg sev-{{ $band['severity'] }}" x="{{ round($x0, 1) }}" y="{{ $y }}" width="{{ round($w, 1) }}" height="{{ $barH }}">
                        <title>{{ $label }} – {{ $band['label'] }}: {{ $c }} von {{ $g['n'] }} ({{ $pct($c, $g['n']) }} %)</title>
                    </rect>
                    @if($w >= 30)
                        <text class="seg-count" x="{{ round($x0 + $w / 2, 1) }}" y="{{ $y + $barH / 2 + 5 }}" text-anchor="middle">{{ $c }}</text>
                    @endif
                    @php $x0 += $w; @endphp
                @endif
            @endforeach
            <text x="{{ $W - 8 }}" y="{{ $y + $barH / 2 + 5 }}" text-anchor="end">n = {{ $g['n'] }}</text>
        @endforeach
    </svg>
</div>
