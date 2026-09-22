{{--
    Mehrzeiliger LQ-Boxplot (eine Zeile je Gruppe, gemeinsame x-Achse) als SVG.
    Gleiche Darstellung auf dem Bildschirm (Filament-Farbvariablen) und im PDF (print = feste Farben).
    groups: list<{key, label, sublabel?, n, too_small, summary?, gender?, points: list<{lq, gender, label}>}>
--}}
@props([
    'groups' => [],
    'domain' => [60, 140],
    'bands' => [],
    'threshold' => 85,
    'thresholdLabel' => 'auffällig',
    'showBands' => false,
    'byGender' => false,
    'genderInfo' => null,
    'print' => false,
    'labels' => null,
    'drilldown' => false,
])
@php
    $labels ??= count($groups) > 1;
    [$d0, $d1] = $domain;
    $W = 800;
    $padL = $labels ? 170 : 20;
    $padR = $labels ? 64 : 20;
    $top = 30;
    $rowH = $labels ? 46 : 90;
    $plotH = max(1, count($groups)) * $rowH;
    $axisY = $top + $plotH;
    $H = $axisY + 32;
    $x = fn ($v) => round($padL + ($v - $d0) / max(1, $d1 - $d0) * ($W - $padL - $padR), 1);
    $fmt = fn ($v) => $v === null ? '–' : rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',');
    $norm = \App\Domain\Analytics\DistributionStats::NORM_MEAN;
    $total = array_sum(array_column($bands, 'count'));
    $genderLabels = \App\Domain\Analytics\DistributionStats::GENDER_LABELS;
    $aria = count($groups) === 1 && ($groups[0]['summary'] ?? null)
        ? 'Boxplot der LQ-Werte: Median '.$fmt($groups[0]['summary']['median']).', Quartile '.$fmt($groups[0]['summary']['q1']).' bis '.$fmt($groups[0]['summary']['q3'])
        : 'Boxplot der LQ-Werte je Gruppe: '.collect($groups)->map(fn ($g) => trim($g['label'].' '.($g['sublabel'] ?? '')).' Median '.$fmt($g['summary']['median'] ?? null))->implode('; ');
@endphp

<div {{ $attributes->class(['an-chart', 'an-print' => $print]) }}>
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
        .an-plot text { fill: var(--an-text); font-size: 14px; }
        .an-plot text.an-label { fill: var(--an-strong); font-size: 15px; }
        .an-plot text.an-sub { fill: var(--an-text); }
        .an-plot .axis { stroke: var(--an-axis); }
        .an-plot .grid { stroke: var(--an-grid); }
        .an-plot .rowbg { fill: var(--an-row); }
        .an-plot .whisker { stroke: var(--an-whisker); stroke-width: 2; }
        .an-plot .box { fill: var(--an-box); stroke: var(--an-box-line); stroke-width: 2; }
        .an-plot .box.g-w { fill: color-mix(in srgb, var(--an-w) 16%, transparent); stroke: var(--an-w); }
        .an-plot .box.g-m { fill: color-mix(in srgb, var(--an-m) 16%, transparent); stroke: var(--an-m); }
        .an-plot .median { stroke: var(--an-median); stroke-width: 3; stroke-linecap: round; }
        .an-plot .ref-threshold { stroke: var(--an-thr); stroke-width: 1.5; stroke-dasharray: 5 4; }
        .an-plot .ref-norm { stroke: var(--an-norm); stroke-width: 1.5; stroke-dasharray: 5 4; }
        .an-plot .dot { fill: var(--an-dot); stroke: var(--an-dot-stroke); stroke-width: 1.5; }
        .an-plot .dot.low { fill: var(--an-dot-low); }
        .an-plot .dot.g-w, .an-legend .g-w { fill: var(--an-w); }
        .an-plot .dot.g-m, .an-legend .g-m { fill: var(--an-m); }
        .an-plot .dot.g-other, .an-legend .g-other { fill: var(--an-o); }
        .an-plot .hit:hover + .dot, .an-plot .dot:hover { stroke: rgb(var(--gray-950)); stroke-width: 2; }
        .dark .an-plot .hit:hover + .dot { stroke: #fff; }
        .an-plot .an-link { cursor: pointer; }
        .an-plot .an-link:hover text.an-label { text-decoration: underline; fill: var(--an-box-line); }
        .an-band.sev-foerderbedarf, .an-legend .sev-foerderbedarf { fill: var(--an-b-f); }
        .an-band.sev-auffaellig, .an-legend .sev-auffaellig { fill: var(--an-b-a); }
        .an-band.sev-hinweis, .an-legend .sev-hinweis { fill: var(--an-b-h); }
        .an-band.sev-none, .an-legend .sev-none { fill: var(--an-b-n); }
        .an-legend { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; font-size: .875rem; margin-bottom: .25rem; color: var(--an-strong); }
        .an-legend > span { display: inline-flex; align-items: center; gap: .4rem; }
        .an-legend rect.swatch { stroke: var(--an-axis); }
    </style>

    @if($showBands && $total > 0)
        <div class="an-legend">
            @foreach($bands as $band)
                <span>
                    <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
                        <rect class="swatch sev-{{ $band['severity'] }}" x="0.5" y="0.5" width="13" height="13" rx="3" />
                    </svg>
                    <span>
                        {{ $band['label'] }}
                        ({{ $band['from'] === null ? '< '.($band['to'] + 1) : ($band['to'] === null ? '≥ '.$band['from'] : $band['from'].'–'.$band['to']) }}):
                        <strong>{{ $band['count'] }}</strong> SuS
                        <span style="opacity:.7;">({{ round($band['count'] / $total * 100) }} %)</span>
                    </span>
                </span>
            @endforeach
        </div>
    @endif

    @if($byGender)
        <div class="an-legend">
            @foreach($genderInfo ?? array_fill_keys(array_keys($genderLabels), null) as $g => $info)
                <span>
                    <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">
                        @if($g === 'w')<circle class="g-w" cx="6" cy="6" r="5" />
                        @elseif($g === 'm')<rect class="g-m" x="1.5" y="1.5" width="9" height="9" rx="1" />
                        @else<rect class="g-other" x="2.5" y="2.5" width="7" height="7" transform="rotate(45 6 6)" />@endif
                    </svg>
                    @if($info !== null)
                        {{ $genderLabels[$g] }}: n = {{ $info['n'] }} · Median {{ $fmt($info['median']) }}
                    @else
                        {{ $genderLabels[$g] }}
                    @endif
                </span>
            @endforeach
        </div>
    @endif

    <svg class="an-plot" viewBox="0 0 {{ $W }} {{ $H }}" width="100%" role="img" aria-label="{{ $aria }}"
         style="display:block;{{ $labels ? '' : ' max-height:220px;' }}">
        {{-- Zeilenhintergrund (jede zweite Zeile) --}}
        @foreach($groups as $i => $g)
            @if($labels && $i % 2 === 1)
                <rect class="rowbg" x="0" y="{{ $top + $i * $rowH }}" width="{{ $W }}" height="{{ $rowH }}" />
            @endif
        @endforeach

        {{-- Förderbereiche als Hintergrundflächen --}}
        @if($showBands)
            @foreach($bands as $band)
                @php
                    $bx0 = $x(max($d0, $band['from'] ?? $d0));
                    $bx1 = $x(min($d1, $band['to'] === null ? $d1 : $band['to'] + 1));
                @endphp
                @if($bx1 > $bx0)
                    <rect class="an-band sev-{{ $band['severity'] }}" x="{{ $bx0 }}" y="{{ $top - 8 }}" width="{{ $bx1 - $bx0 }}" height="{{ $plotH + 8 }}">
                        <title>{{ $band['label'] }}: {{ $band['count'] }} SuS</title>
                    </rect>
                @endif
            @endforeach
        @endif

        {{-- Raster + Achse --}}
        @for($t = $d0; $t <= $d1; $t += 10)
            <line class="grid" x1="{{ $x($t) }}" x2="{{ $x($t) }}" y1="{{ $top }}" y2="{{ $axisY }}" />
            <text x="{{ $x($t) }}" y="{{ $axisY + 22 }}" text-anchor="middle">{{ $t }}</text>
        @endfor
        <line class="axis" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $axisY }}" y2="{{ $axisY }}" />

        {{-- Referenzlinien --}}
        <line class="ref-threshold" x1="{{ $x($threshold) }}" x2="{{ $x($threshold) }}" y1="{{ $top - 8 }}" y2="{{ $axisY }}" />
        <text x="{{ $x($threshold) - 4 }}" y="{{ $top - 14 }}" text-anchor="end">{{ $thresholdLabel }} &lt; {{ $threshold }}</text>
        <line class="ref-norm" x1="{{ $x($norm) }}" x2="{{ $x($norm) }}" y1="{{ $top - 8 }}" y2="{{ $axisY }}" />
        <text x="{{ $x($norm) + 4 }}" y="{{ $top - 14 }}">Normmittel {{ $norm }}</text>

        @foreach($groups as $i => $g)
            @php
                $cyRow = $top + $i * $rowH + $rowH / 2;
                $boxH = $rowH * ($labels ? 0.5 : 0.53);
                $s = $g['summary'] ?? null;
                $boxGender = $byGender && in_array($g['gender'] ?? null, ['w', 'm'], true) ? 'g-'.$g['gender'] : '';
            @endphp

            @if($labels)
                <g @if($drilldown && ! $print) class="an-link" wire:click="mountAction('groupStudents', { group: @js($g['key']) })" @endif>
                    <title>{{ trim($g['label'].' '.($g['sublabel'] ?? '')) }}{{ $drilldown && ! $print ? ' – Schülerliste öffnen' : '' }}</title>
                    <rect x="0" y="{{ $top + $i * $rowH }}" width="{{ $padL - 8 }}" height="{{ $rowH }}" fill="transparent" />
                    <text class="an-label" x="8" y="{{ $cyRow + 5 }}">
                        {{ \Illuminate\Support\Str::limit($g['label'], $g['sublabel'] ?? null ? 10 : 20) }}@if($g['sublabel'] ?? null)<tspan class="an-sub"> · {{ \Illuminate\Support\Str::limit($g['sublabel'], 9, '.') }}</tspan>@endif
                    </text>
                </g>
                <text x="{{ $W - 8 }}" y="{{ $cyRow + 5 }}" text-anchor="end">n = {{ $g['n'] }}</text>
            @endif

            @if($s && ! $g['too_small'])
                <g>
                    <title>{{ trim($g['label'].' '.($g['sublabel'] ?? '')) }}: Median {{ $fmt($s['median']) }} · Q1 {{ $fmt($s['q1']) }} · Q3 {{ $fmt($s['q3']) }} · Whisker {{ $fmt($s['lo']) }}–{{ $fmt($s['hi']) }}</title>
                    <line class="whisker" x1="{{ $x($s['lo']) }}" x2="{{ $x($s['q1']) }}" y1="{{ $cyRow }}" y2="{{ $cyRow }}" />
                    <line class="whisker" x1="{{ $x($s['q3']) }}" x2="{{ $x($s['hi']) }}" y1="{{ $cyRow }}" y2="{{ $cyRow }}" />
                    <line class="whisker" x1="{{ $x($s['lo']) }}" x2="{{ $x($s['lo']) }}" y1="{{ $cyRow - $boxH / 4 }}" y2="{{ $cyRow + $boxH / 4 }}" />
                    <line class="whisker" x1="{{ $x($s['hi']) }}" x2="{{ $x($s['hi']) }}" y1="{{ $cyRow - $boxH / 4 }}" y2="{{ $cyRow + $boxH / 4 }}" />
                    <rect class="box {{ $boxGender }}" x="{{ $x($s['q1']) }}" y="{{ $cyRow - $boxH / 2 }}" rx="4"
                          width="{{ max(2, $x($s['q3']) - $x($s['q1'])) }}" height="{{ $boxH }}" />
                    <line class="median" x1="{{ $x($s['median']) }}" x2="{{ $x($s['median']) }}" y1="{{ $cyRow - $boxH / 2 }}" y2="{{ $cyRow + $boxH / 2 }}" />
                </g>
            @endif

            {{-- Einzelwerte (deterministisch gestreut, damit gleiche Werte sichtbar bleiben) --}}
            @foreach($g['points'] as $j => $v)
                @php
                    $cy = $cyRow + ((($j * 7) % 9) - 4) * ($labels ? 2.4 : 4.5);
                    $cx = $x($v['lq']);
                    $r = $labels ? 4 : 4.5;
                @endphp
                <g>
                    <title>{{ $v['label'] }}</title>
                    @unless($print)<circle class="hit" cx="{{ $cx }}" cy="{{ $cy }}" r="9" fill="transparent" />@endunless
                    @if(! $byGender)
                        <circle class="dot {{ $v['lq'] < $threshold ? 'low' : '' }}" cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" />
                    @elseif($v['gender'] === 'w')
                        <circle class="dot g-w" cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r + 0.5 }}" />
                    @elseif($v['gender'] === 'm')
                        <rect class="dot g-m" x="{{ $cx - $r }}" y="{{ $cy - $r }}" width="{{ 2 * $r }}" height="{{ 2 * $r }}" rx="1" />
                    @else
                        <rect class="dot g-other" x="{{ $cx - 3.5 }}" y="{{ $cy - 3.5 }}" width="7" height="7"
                              transform="rotate(45 {{ $cx }} {{ $cy }})" />
                    @endif
                </g>
            @endforeach
        @endforeach
    </svg>

    @if($labels && collect($groups)->contains('too_small', true))
        <p style="font-size:.8rem; color:var(--an-text); margin-top:.25rem;">
            Gruppen mit weniger als {{ \App\Domain\Analytics\AnalysisReport::MIN_GROUP_SIZE }} Werten zeigen nur Einzelpunkte (keine Box).
        </p>
    @endif
</div>
