{{--
    Tempo (bearbeitete Sätze, x) gegen Fehlerquote (y) je Schüler:in, Hilfslinien an den Medianen,
    Quadranten beschriftet; darunter Anzahlen und die beiden für Rückmeldungen interessanten Gruppen.
    sa = AnalysisReport::speedAccuracy(); names = Klarnamen zeigen (sonst Schülercode).
--}}
@props([
    'sa' => [],
    'byGender' => false,
    'print' => false,
    'names' => true,
    'limit' => 10,
])
@php
    $W = 800; $H = 380; $padL = 70; $padR = 16; $top = 16; $bottom = 44;
    $plotW = $W - $padL - $padR; $plotH = $H - $top - $bottom;
    $xMin = $sa['x_min']; $xMax = max($xMin + 10, $sa['x_max']); $yMax = $sa['y_max'];
    $x = fn ($v) => round($padL + ($v - $xMin) / ($xMax - $xMin) * $plotW, 1);
    $y = fn ($v) => round($top + $plotH - $v / $yMax * $plotH, 1);
    $fmt = fn ($v, $d = 1) => number_format((float) $v, $d, ',', '.');
    $xStep = $xMax - $xMin <= 40 ? 5 : ($xMax - $xMin <= 100 ? 10 : 20);
    $display = fn (array $r) => $names && $r['name'] !== '' && ! str_contains($r['name'], '***') ? $r['name'] : $r['student_code'];
    $counts = collect($sa['quadrants'])->keyBy('key');
@endphp

<div {{ $attributes->class(['an-chart', 'an-print' => $print]) }}>
    <x-analysis.tokens />
    <style>
        .an-scatter text { fill: var(--an-text); font-size: 14px; }
        .an-scatter text.quad { font-size: 13px; font-weight: 600; fill: var(--an-strong); opacity: .55; }
        .an-scatter .grid { stroke: var(--an-grid); }
        .an-scatter .axis { stroke: var(--an-axis); }
        .an-scatter .median { stroke: var(--an-norm); stroke-width: 1.5; stroke-dasharray: 5 4; }
        .an-scatter .dot { fill: var(--an-dot); stroke: var(--an-dot-stroke); stroke-width: 1.2; }
        .an-scatter .dot.low { fill: var(--an-dot-low); }
        .an-scatter .dot.g-w { fill: var(--an-w); }
        .an-scatter .dot.g-m { fill: var(--an-m); }
        .an-scatter .dot.g-other { fill: var(--an-o); }
        .an-scatter g:hover .dot { stroke: var(--an-strong); stroke-width: 2; }
        .an-chart .an-legend { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; font-size: .875rem; margin-bottom: .25rem; color: var(--an-strong); }
        .an-chart .an-legend > span { display: inline-flex; align-items: center; gap: .4rem; }
        .an-legend .g-w { fill: var(--an-w); } .an-legend .g-m { fill: var(--an-m); } .an-legend .g-other { fill: var(--an-o); }
        .an-sa-lists { display: grid; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); gap: 1rem; margin-top: .75rem; }
        .an-sa-lists h4 { font-weight: 600; margin-bottom: .25rem; }
        .an-sa-hint { font-size: .8rem; color: var(--an-text); }
    </style>

    @if($byGender)
        <div class="an-legend">
            <span><svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><circle class="g-w" cx="6" cy="6" r="5" /></svg> Mädchen</span>
            <span><svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><rect class="g-m" x="1.5" y="1.5" width="9" height="9" rx="1" /></svg> Jungen</span>
            <span><svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><rect class="g-other" x="2.5" y="2.5" width="7" height="7" transform="rotate(45 6 6)" /></svg> divers/unbekannt</span>
        </div>
    @endif

    <svg class="an-scatter" viewBox="0 0 {{ $W }} {{ $H }}" width="100%" role="img" style="display:block;"
         aria-label="Tempo gegen Fehlerquote für {{ $sa['n'] }} Schüler:innen; Median {{ $fmt($sa['median_x'], 0) }} bearbeitete Sätze, Median-Fehlerquote {{ $fmt($sa['median_y']) }} %">
        @for($t = 0; $t <= $yMax; $t += 10)
            <line class="grid" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y($t) }}" y2="{{ $y($t) }}" />
            <text x="{{ $padL - 8 }}" y="{{ $y($t) + 5 }}" text-anchor="end">{{ $t }} %</text>
        @endfor
        @for($t = (int) (ceil($xMin / $xStep) * $xStep); $t <= $xMax; $t += $xStep)
            <text x="{{ $x($t) }}" y="{{ $top + $plotH + 20 }}" text-anchor="middle">{{ $t }}</text>
        @endfor
        <text x="{{ $padL + $plotW / 2 }}" y="{{ $H - 4 }}" text-anchor="middle">bearbeitete Sätze (Tempo) →</text>
        <text x="14" y="{{ $top + $plotH / 2 }}" text-anchor="middle" transform="rotate(-90 14 {{ $top + $plotH / 2 }})">Fehlerquote →</text>
        <line class="axis" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $top + $plotH }}" y2="{{ $top + $plotH }}" />

        {{-- Median-Hilfslinien + Quadranten --}}
        <line class="median" x1="{{ $x($sa['median_x']) }}" x2="{{ $x($sa['median_x']) }}" y1="{{ $top }}" y2="{{ $top + $plotH }}" />
        <line class="median" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y($sa['median_y']) }}" y2="{{ $y($sa['median_y']) }}" />
        <text class="quad" x="{{ $padL + 8 }}" y="{{ $top + 18 }}">langsam &amp; fehlerhaft ({{ $counts['slow_inaccurate']['count'] }})</text>
        <text class="quad" x="{{ $W - $padR - 8 }}" y="{{ $top + 18 }}" text-anchor="end">schnell &amp; fehlerhaft ({{ $counts['fast_inaccurate']['count'] }})</text>
        <text class="quad" x="{{ $padL + 8 }}" y="{{ $top + $plotH - 10 }}">langsam &amp; genau ({{ $counts['slow_accurate']['count'] }})</text>
        <text class="quad" x="{{ $W - $padR - 8 }}" y="{{ $top + $plotH - 10 }}" text-anchor="end">schnell &amp; genau ({{ $counts['fast_accurate']['count'] }})</text>

        @foreach($sa['points'] as $i => $p)
            @php
                // leichte, feste Streuung, damit gleiche Werte sichtbar bleiben
                $cx = $x($p['answered_count']) + ((($i * 5) % 7) - 3) * 1.2;
                $cy = $y($p['error_rate']) + ((($i * 3) % 5) - 2) * 1.2;
                $cls = $byGender ? 'g-'.$p['gender'] : ($p['severity'] !== 'none' ? 'low' : '');
            @endphp
            <g>
                <title>{{ $display($p) }}: {{ $p['answered_count'] }} Sätze, {{ $p['errors'] }} Fehler ({{ $fmt($p['error_rate']) }} %), LQ {{ $p['lq'] }}</title>
                @if($byGender && $p['gender'] === 'm')
                    <rect class="dot {{ $cls }}" x="{{ $cx - 4 }}" y="{{ $cy - 4 }}" width="8" height="8" rx="1" />
                @elseif($byGender && $p['gender'] === 'other')
                    <rect class="dot {{ $cls }}" x="{{ $cx - 3.5 }}" y="{{ $cy - 3.5 }}" width="7" height="7" transform="rotate(45 {{ $cx }} {{ $cy }})" />
                @else
                    <circle class="dot {{ $cls }}" cx="{{ $cx }}" cy="{{ $cy }}" r="4.5" />
                @endif
            </g>
        @endforeach
    </svg>
    <p class="an-sa-hint">
        Gestrichelt = Median ({{ $fmt($sa['median_x'], 0) }} Sätze, {{ $fmt($sa['median_y']) }} % Fehler).
        @unless($byGender) Orange = LQ im Förderbereich. @endunless
        Fehlerquote = falsch beurteilte / bearbeitete Sätze.
    </p>

    <div class="an-sa-lists">
        @foreach(['fast_inaccurate' => 'Schnell, aber fehlerhaft – eher genauer lesen üben', 'slow_accurate' => 'Genau, aber langsam – eher Lesetempo üben'] as $key => $title)
            @php $list = collect($sa[$key])->take($print ? 25 : $limit); @endphp
            <div>
                <h4>{{ $title }} ({{ count($sa[$key]) }})</h4>
                @if($list->isEmpty())
                    <p class="an-sa-hint">Niemand in diesem Bereich.</p>
                @else
                    <table class="an-table">
                        <thead><tr><th>{{ $names ? 'Name' : 'Schülercode' }}</th><th style="text-align:left;">Klasse</th><th>Sätze</th><th>Fehler</th><th>LQ</th></tr></thead>
                        <tbody>
                            @foreach($list as $p)
                                <tr>
                                    <td>{{ $display($p) }}</td>
                                    <td style="text-align:left;">{{ $p['learning_group_name'] }}</td>
                                    <td>{{ $p['answered_count'] }}</td>
                                    <td>{{ $fmt($p['error_rate'], 0) }} %</td>
                                    <td>{{ $p['lq'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if(count($sa[$key]) > $list->count())
                        <p class="an-sa-hint">{{ $list->count() }} von {{ count($sa[$key]) }} gezeigt.</p>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
</div>
