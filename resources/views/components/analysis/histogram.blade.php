{{--
    LQ-Häufigkeitsverteilung (Balken je Klasse) mit Normkurve N(100; 15), Mittelwert- und Schwellenlinie,
    darunter beobachtete vs. laut Norm erwartete Anteile je Förderbereich.
    hist = AnalysisReport::histogram().
--}}
@props([
    'hist' => [],
    'threshold' => 85,
    'thresholdLabel' => 'auffällig',
    'print' => false,
])
@php
    [$d0, $d1] = $hist['domain'];
    $W = 800; $H = 300; $padL = 44; $padR = 16; $top = 28; $bottom = 34;
    $plotW = $W - $padL - $padR; $plotH = $H - $top - $bottom;
    // y-Achse auf „schöne“ Schritte runden
    $step = $hist['max'] <= 10 ? 2 : ($hist['max'] <= 25 ? 5 : ($hist['max'] <= 60 ? 10 : 20));
    $yMax = (int) (ceil($hist['max'] / $step) * $step);
    $x = fn ($v) => round($padL + ($v - $d0) / max(1, $d1 - $d0) * $plotW, 1);
    $y = fn ($v) => round($top + $plotH - $v / max(1, $yMax) * $plotH, 1);
    $fmt = fn ($v, $d = 1) => number_format((float) $v, $d, ',', '.');
    $mean = $hist['summary']['mean'] ?? null;
    $norm = \App\Domain\Analytics\DistributionStats::NORM_MEAN;
    $curve = collect($hist['curve'])->map(fn ($p) => $x($p[0]).','.$y($p[1]))->implode(' ');
@endphp

<div {{ $attributes->class(['an-chart', 'an-print' => $print]) }}>
    <x-analysis.tokens />
    <style>
        .an-hist text { fill: var(--an-text); font-size: 14px; }
        .an-hist .grid { stroke: var(--an-grid); }
        .an-hist .axis { stroke: var(--an-axis); }
        .an-hist .bar { fill: var(--an-box); stroke: var(--an-box-line); stroke-width: 1; }
        .an-hist .bar.sev-foerderbedarf { fill: var(--an-s-f); stroke: none; }
        .an-hist .bar.sev-auffaellig { fill: var(--an-s-a); stroke: none; }
        .an-hist .bar.sev-hinweis { fill: var(--an-s-h); stroke: none; }
        .an-hist .curve { fill: none; stroke: var(--an-strong); stroke-width: 2; stroke-dasharray: 6 4; }
        .an-hist .ref-threshold { stroke: var(--an-thr); stroke-width: 1.5; stroke-dasharray: 5 4; }
        .an-hist .mean { stroke: var(--an-median); stroke-width: 2.5; }
        .an-hist-legend { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; font-size: .875rem; margin-bottom: .25rem; color: var(--an-strong); }
        .an-hist-legend > span { display: inline-flex; align-items: center; gap: .4rem; }
    </style>

    <div class="an-hist-legend">
        <span><svg width="14" height="14" aria-hidden="true"><rect x="0.5" y="0.5" width="13" height="13" rx="2" style="fill: var(--an-box); stroke: var(--an-box-line);" /></svg> Beobachtet (Anzahl je {{ $hist['bin_width'] }} LQ-Punkte)</span>
        <span><svg width="22" height="10" aria-hidden="true"><line x1="0" x2="22" y1="5" y2="5" style="stroke: var(--an-strong); stroke-width: 2; stroke-dasharray: 6 4;" /></svg> Erwartet laut Norm (Mittel 100, SD 15)</span>
        @if($mean !== null)
            <span><svg width="14" height="12" aria-hidden="true"><line x1="7" x2="7" y1="0" y2="12" style="stroke: var(--an-median); stroke-width: 2.5;" /></svg> Mittelwert {{ $fmt($mean) }}</span>
        @endif
    </div>

    <svg class="an-hist" viewBox="0 0 {{ $W }} {{ $H }}" width="100%" role="img" style="display:block;"
         aria-label="Verteilung der LQ-Werte, n = {{ $hist['n'] }}, Mittelwert {{ $mean === null ? '–' : $fmt($mean) }} gegenüber Normmittel 100">
        @for($t = 0; $t <= $yMax; $t += $step)
            <line class="grid" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y($t) }}" y2="{{ $y($t) }}" />
            <text x="{{ $padL - 8 }}" y="{{ $y($t) + 5 }}" text-anchor="end">{{ $t }}</text>
        @endfor
        @for($t = $d0; $t <= $d1; $t += 10)
            <text x="{{ $x($t) }}" y="{{ $H - 10 }}" text-anchor="middle">{{ $t }}</text>
        @endfor

        @foreach($hist['bins'] as $b)
            @php $sev = \App\Domain\Analytics\DistributionStats::severityOf($b['from']); @endphp
            @if($b['count'] > 0)
                <rect class="bar sev-{{ $sev }}" x="{{ $x($b['from']) + 1 }}" y="{{ $y($b['count']) }}"
                      width="{{ max(1, $x($b['to'] + 1) - $x($b['from']) - 2) }}" height="{{ round($top + $plotH - $y($b['count']), 1) }}">
                    <title>LQ {{ $b['from'] }}–{{ $b['to'] }}: {{ $b['count'] }} SuS (laut Norm erwartet {{ $fmt($b['expected']) }})</title>
                </rect>
            @endif
        @endforeach

        <polyline class="curve" points="{{ $curve }}" />
        <line class="axis" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $top + $plotH }}" y2="{{ $top + $plotH }}" />

        <line class="ref-threshold" x1="{{ $x($threshold) }}" x2="{{ $x($threshold) }}" y1="{{ $top - 10 }}" y2="{{ $top + $plotH }}" />
        <text x="{{ $x($threshold) - 4 }}" y="{{ $top - 14 }}" text-anchor="end">{{ $thresholdLabel }} &lt; {{ $threshold }}</text>
        @if($mean !== null)
            <line class="mean" x1="{{ $x($mean) }}" x2="{{ $x($mean) }}" y1="{{ $top - 10 }}" y2="{{ $top + $plotH }}"><title>Mittelwert {{ $fmt($mean) }}</title></line>
            <text x="{{ $x($mean) + 4 }}" y="{{ $top - 14 }}">MW {{ $fmt($mean) }}</text>
        @endif
    </svg>

    <div style="overflow-x:auto; margin-top:.75rem;">
        <table class="an-table">
            <thead>
                <tr>
                    <th>Förderbereich</th>
                    <th>beobachtet</th>
                    <th>%</th>
                    <th>laut Norm erwartet</th>
                    <th>%</th>
                    <th>Abweichung</th>
                </tr>
            </thead>
            <tbody>
                @foreach($hist['shares'] as $s)
                    @php $diff = $s['observed_pct'] - $s['expected_pct']; @endphp
                    <tr>
                        <td>{{ $s['label'] }} ({{ $s['from'] === null ? '< '.($s['to'] + 1) : ($s['to'] === null ? '≥ '.$s['from'] : $s['from'].'–'.$s['to']) }})</td>
                        <td>{{ $s['count'] }}</td>
                        <td>{{ $fmt($s['observed_pct']) }} %</td>
                        <td>{{ $fmt($s['expected_count']) }}</td>
                        <td>{{ $fmt($s['expected_pct']) }} %</td>
                        <td>{{ ($diff > 0 ? '+' : '').$fmt($diff) }} %-Pkt.</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
