{{--
    Entwicklung über Erhebungswellen: je Gruppe Median-Linie mit Q1–Q3-Band.
    dev = AnalysisReport::development() (waves, series, domain).
--}}
@props([
    'dev' => [],
    'threshold' => 85,
    'thresholdLabel' => 'auffällig',
    'print' => false,
])
@php
    $waves = $dev['waves'];
    $series = $dev['series'];
    $W = 800; $H = 320; $padL = 48; $padR = 28; $top = 16; $bottom = 52;
    $plotW = $W - $padL - $padR; $plotH = $H - $top - $bottom;
    // y-Bereich aus den Quartilen, Schwelle und Normmittel, auf 10 gerundet
    $vals = [$threshold, 100];
    foreach ($series as $s) {
        foreach ($s['points'] as $p) {
            if ($p) { $vals[] = $p['q1']; $vals[] = $p['q3']; }
        }
    }
    $y0 = (int) (floor((min($vals) - 5) / 10) * 10);
    $y1 = (int) (ceil((max($vals) + 5) / 10) * 10);
    // rechts bleibt Platz für die Beschriftung der Referenzlinien
    $x = fn (int $i) => round($padL + (count($waves) === 1 ? $plotW * 0.42 : $plotW * (0.06 + 0.72 * $i / (count($waves) - 1))), 1);
    $y = fn ($v) => round($top + $plotH - ($v - $y0) / max(1, $y1 - $y0) * $plotH, 1);
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',');
    $single = count($series) === 1;
    $colorOf = fn (int $i) => $single ? 'var(--an-box-line)' : 'var(--an-c'.($i + 1).')';
    $showValues = count($series) <= 3;
    // Quartilsbänder nur bei wenigen Linien, sonst überdecken sie sich
    $showBands = count($series) <= 2;
@endphp

<div {{ $attributes->class(['an-chart', 'an-print' => $print]) }}>
    <x-analysis.tokens />
    <style>
        .an-dev text { fill: var(--an-text); font-size: 14px; }
        .an-dev text.val { font-size: 13px; font-weight: 600; fill: var(--an-strong); }
        .an-dev .grid { stroke: var(--an-grid); }
        .an-dev .axis { stroke: var(--an-axis); }
        .an-dev .ref-threshold { stroke: var(--an-thr); stroke-width: 1.5; stroke-dasharray: 5 4; }
        .an-dev .ref-norm { stroke: var(--an-norm); stroke-width: 1.5; stroke-dasharray: 5 4; }
        .an-dev .iqr { fill-opacity: .13; stroke: none; }
        .an-dev .line { fill: none; stroke-width: 2.5; stroke-linejoin: round; }
        .an-dev .pt { stroke: var(--an-dot-stroke); stroke-width: 1.5; }
        .an-dev-legend { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; font-size: .875rem; margin-bottom: .25rem; color: var(--an-strong); }
        .an-dev-legend > span { display: inline-flex; align-items: center; gap: .4rem; }
    </style>

    @unless($single)
        <div class="an-dev-legend">
            @foreach($series as $i => $s)
                <span>
                    <svg width="22" height="12" viewBox="0 0 22 12" aria-hidden="true">
                        <rect x="0" y="1" width="22" height="10" rx="2" style="fill: {{ $colorOf($i) }}; fill-opacity: .15;" />
                        <line x1="1" x2="21" y1="6" y2="6" style="stroke: {{ $colorOf($i) }}; stroke-width: 2.5;" />
                    </svg>
                    {{ $s['label'] }}
                </span>
            @endforeach
        </div>
    @endunless

    <svg class="an-dev" viewBox="0 0 {{ $W }} {{ $H }}" width="100%" role="img" style="display:block;"
         aria-label="Median-LQ je Erhebung: {{ collect($series)->map(fn ($s) => $s['label'].' '.collect($s['points'])->map(fn ($p, $i) => $waves[$i]['label'].' '.($p ? $fmt($p['median']) : '–'))->implode(', '))->implode('; ') }}">
        @for($t = $y0; $t <= $y1; $t += 10)
            <line class="grid" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y($t) }}" y2="{{ $y($t) }}" />
            <text x="{{ $padL - 8 }}" y="{{ $y($t) + 5 }}" text-anchor="end">{{ $t }}</text>
        @endfor
        <line class="ref-threshold" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y($threshold) }}" y2="{{ $y($threshold) }}" />
        <text x="{{ $W - $padR }}" y="{{ $y($threshold) - 5 }}" text-anchor="end">{{ $thresholdLabel }} &lt; {{ $threshold }}</text>
        <line class="ref-norm" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y(100) }}" y2="{{ $y(100) }}" />
        <text x="{{ $W - $padR }}" y="{{ $y(100) - 5 }}" text-anchor="end">Normmittel 100</text>
        <line class="axis" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $top + $plotH }}" y2="{{ $top + $plotH }}" />

        @foreach($waves as $i => $w)
            <text x="{{ $x($i) }}" y="{{ $top + $plotH + 22 }}" text-anchor="middle">{{ \Illuminate\Support\Str::limit($w['label'], 22) }}</text>
            <text x="{{ $x($i) }}" y="{{ $top + $plotH + 40 }}" text-anchor="middle" style="font-size:12px;">n = {{ $w['n'] }}</text>
        @endforeach

        @foreach($series as $si => $s)
            @php
                // Zusammenhängende Abschnitte (Wellen ohne Daten unterbrechen die Linie)
                $segments = []; $cur = [];
                foreach ($s['points'] as $i => $p) {
                    if ($p === null) { if ($cur) { $segments[] = $cur; } $cur = []; continue; }
                    $cur[] = [$i, $p];
                }
                if ($cur) { $segments[] = $cur; }
                $color = $colorOf($si);
            @endphp
            @foreach($segments as $seg)
                @php
                    $upper = collect($seg)->map(fn ($e) => $x($e[0]).','.$y($e[1]['q3']))->implode(' ');
                    $lower = collect(array_reverse($seg))->map(fn ($e) => $x($e[0]).','.$y($e[1]['q1']))->implode(' ');
                @endphp
                @if(count($seg) > 1 && $showBands)
                    <polygon class="iqr" points="{{ $upper }} {{ $lower }}" style="fill: {{ $color }};" />
                @endif
                @if(count($seg) > 1)
                    <polyline class="line" points="{{ collect($seg)->map(fn ($e) => $x($e[0]).','.$y($e[1]['median']))->implode(' ') }}" style="stroke: {{ $color }};" />
                @endif
                @foreach($seg as [$i, $p])
                    <g>
                        <title>{{ $s['label'] }} – {{ $waves[$i]['label'] }}: Median {{ $fmt($p['median']) }}, Q1–Q3 {{ $fmt($p['q1']) }}–{{ $fmt($p['q3']) }}, n = {{ $p['n'] }}</title>
                        @if(count($seg) === 1 && $showBands)
                            <line x1="{{ $x($i) }}" x2="{{ $x($i) }}" y1="{{ $y($p['q1']) }}" y2="{{ $y($p['q3']) }}" style="stroke: {{ $color }}; stroke-width: 6; stroke-opacity: .25;" />
                        @endif
                        <circle class="pt" cx="{{ $x($i) }}" cy="{{ $y($p['median']) }}" r="5" style="fill: {{ $color }};" />
                        @if($showValues)
                            <text class="val" x="{{ $x($i) + 9 }}" y="{{ $y($p['median']) - 8 }}">{{ $fmt($p['median']) }}</text>
                        @endif
                    </g>
                @endforeach
            @endforeach
        @endforeach
    </svg>
    <p style="font-size:.8rem; color:var(--an-text); margin-top:.25rem;">
        Linie = Median{{ $showBands ? ', Fläche = mittlere 50 % (Q1–Q3)' : ' (Quartile im Tooltip)' }}. Je Schüler zählt der letzte Versuch jeder Erhebung.
        @if($dev['too_many_series'] ?? false) Mehr als {{ \App\Domain\Analytics\AnalysisReport::MAX_SERIES }} Gruppen – daher nur die Gesamtlinie. @endif
    </p>
</div>
