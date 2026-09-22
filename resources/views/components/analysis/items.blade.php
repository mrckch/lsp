{{--
    Satzanalyse: Lösungsquote je Satz (Balken) und Anteil der Schüler:innen, die den Satz erreicht haben (Linie),
    darunter die Tabelle. ia = ItemAnalysis::analyse().
--}}
@props([
    'ia' => [],
    'print' => false,
])
@php
    $items = $ia['items'];
    $count = max(1, count($items));
    $W = 800; $H = 260; $padL = 48; $padR = 16; $top = 16; $bottom = 36;
    $plotW = $W - $padL - $padR; $plotH = $H - $top - $bottom;
    $slot = $plotW / $count;
    $bw = max(1.5, min(18, $slot * 0.72));
    $cx = fn (int $i) => round($padL + ($i + 0.5) * $slot, 1);
    $y = fn ($pct) => round($top + $plotH - $pct / 100 * $plotH, 1);
    $fmt = fn ($v, $d = 0) => $v === null ? '–' : number_format((float) $v, $d, ',', '.');
    $labelEvery = $count <= 25 ? 1 : ($count <= 60 ? 5 : 10);
    $hardCut = \App\Domain\Analytics\ItemAnalysis::HARD_BELOW_PCT;
    $reachLine = collect($items)->map(fn ($it, $i) => $cx($i).','.$y($it['reached_pct']))->implode(' ');
@endphp

<div {{ $attributes->class(['an-chart', 'an-print' => $print]) }}>
    <x-analysis.tokens />
    <style>
        .an-items text { fill: var(--an-text); font-size: 14px; }
        .an-items .grid { stroke: var(--an-grid); }
        .an-items .axis { stroke: var(--an-axis); }
        .an-items .bar { fill: var(--an-box-line); fill-opacity: .55; }
        .an-items .bar.hard { fill: var(--an-dot-low); fill-opacity: .85; }
        .an-items .reach { fill: none; stroke: var(--an-strong); stroke-width: 2; }
        .an-items .cut { stroke: var(--an-thr); stroke-width: 1.5; stroke-dasharray: 5 4; }
        .an-items-legend { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; font-size: .875rem; margin-bottom: .25rem; color: var(--an-strong); }
        .an-items-legend > span { display: inline-flex; align-items: center; gap: .4rem; }
        .an-items-table td.text { text-align: left; white-space: normal; min-width: 14rem; }
        .an-items-table tr.hard td { background: color-mix(in srgb, var(--an-dot-low) 10%, transparent); }
    </style>

    <div class="an-items-legend">
        <span><svg width="14" height="14" aria-hidden="true"><rect x="1" y="1" width="12" height="12" rx="2" style="fill: var(--an-box-line); fill-opacity: .55;" /></svg> Lösungsquote (richtig / bearbeitet)</span>
        <span><svg width="14" height="14" aria-hidden="true"><rect x="1" y="1" width="12" height="12" rx="2" style="fill: var(--an-dot-low); fill-opacity: .85;" /></svg> schwierig (unter {{ $hardCut }} %)</span>
        <span><svg width="22" height="10" aria-hidden="true"><line x1="0" x2="22" y1="5" y2="5" style="stroke: var(--an-strong); stroke-width: 2;" /></svg> erreicht (% der Schüler:innen)</span>
    </div>

    <svg class="an-items" viewBox="0 0 {{ $W }} {{ $H }}" width="100%" role="img" style="display:block;"
         aria-label="Lösungsquote je Satz für {{ count($items) }} Sätze; {{ $ia['hard_count'] }} schwierige Sätze">
        @foreach([0, 25, 50, 75, 100] as $t)
            <line class="grid" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y($t) }}" y2="{{ $y($t) }}" />
            <text x="{{ $padL - 8 }}" y="{{ $y($t) + 5 }}" text-anchor="end">{{ $t }} %</text>
        @endforeach
        <line class="cut" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y($hardCut) }}" y2="{{ $y($hardCut) }}" />
        @foreach($items as $i => $it)
            @if($it['solution_pct'] !== null)
                <rect class="bar {{ $it['hard'] ? 'hard' : '' }}" x="{{ round($cx($i) - $bw / 2, 1) }}" y="{{ $y($it['solution_pct']) }}"
                      width="{{ round($bw, 1) }}" height="{{ round($top + $plotH - $y($it['solution_pct']), 1) }}">
                    <title>Satz {{ $it['nr'] }}: {{ $fmt($it['solution_pct']) }} % richtig ({{ $it['correct'] }}/{{ $it['answered'] }}), erreicht von {{ $fmt($it['reached_pct']) }} %</title>
                </rect>
            @endif
            @if(($i + 1) % $labelEvery === 0 || $i === 0)
                <text x="{{ $cx($i) }}" y="{{ $top + $plotH + 20 }}" text-anchor="middle">{{ $it['nr'] }}</text>
            @endif
        @endforeach
        <polyline class="reach" points="{{ $reachLine }}" />
        <line class="axis" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $top + $plotH }}" y2="{{ $top + $plotH }}" />
    </svg>
    <p style="font-size:.8rem; color:var(--an-text); margin-top:.25rem;">
        {{ $ia['questionnaire_name'] }} · {{ $ia['attempts'] }} Versuche · {{ count($items) }} Sätze
        @if($ia['median_reached'] !== null) · die Hälfte der Schüler:innen kam mindestens bis Satz {{ $ia['median_reached'] }} @endif
        · „übersprungen“ = vor dem letzten bearbeiteten Satz ausgelassen.
    </p>

    <div style="overflow-x:auto; margin-top:.5rem;">
        <table class="an-table an-items-table">
            <thead>
                <tr>
                    <th>Nr.</th>
                    <th style="text-align:left;">Satz</th>
                    <th>Lösung</th>
                    <th>bearbeitet</th>
                    <th>richtig</th>
                    <th>Lösungsquote</th>
                    <th>übersprungen</th>
                    <th>erreicht</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $it)
                    <tr @class(['hard' => $it['hard']])>
                        <td>{{ $it['nr'] }}</td>
                        <td class="text">{{ \Illuminate\Support\Str::limit($it['text'], $print ? 70 : 110) }}</td>
                        <td>{{ $it['correct_answer'] }}</td>
                        <td>{{ $it['answered'] }}</td>
                        <td>{{ $it['correct'] }}</td>
                        <td>{{ $fmt($it['solution_pct']) }}{{ $it['solution_pct'] === null ? '' : ' %' }}</td>
                        <td>{{ $it['skipped'] }}</td>
                        <td>{{ $fmt($it['reached_pct']) }} %</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
