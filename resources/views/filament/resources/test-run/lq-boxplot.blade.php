@php
    $data = $this->getData();
    $s = $data['stats'];
    [$d0, $d1] = $data['domain'];
    $W = 800; $padL = 20; $padR = 20;
    $x = fn ($v) => round($padL + ($v - $d0) / max(1, $d1 - $d0) * ($W - $padL - $padR), 1);
    $fmt = fn ($v) => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    $threshold = \App\Filament\Resources\TestRunResource\Widgets\TestRunLqBoxplot::THRESHOLD;
    $norm = \App\Filament\Resources\TestRunResource\Widgets\TestRunLqBoxplot::NORM_MEAN;
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Verteilung LQ</x-slot>
        <x-slot name="description">
            @if($s)
                n = {{ $data['n'] }} · Median {{ $fmt($s['median']) }} · Q1–Q3 {{ $fmt($s['q1']) }}–{{ $fmt($s['q3']) }}
                · Min–Max {{ $fmt($s['min']) }}–{{ $fmt($s['max']) }}
                · <strong>{{ $s['below'] }}</strong> unter LQ {{ $threshold }}
            @else
                Noch keine gewerteten Versuche.
            @endif
        </x-slot>

        @if($s)
            <x-slot name="headerEnd">
                <x-filament::button size="sm" :color="$byGender ? 'primary' : 'gray'" :outlined="! $byGender"
                                    icon="heroicon-m-user-group" wire:click="$toggle('byGender')">
                    Nach Geschlecht
                </x-filament::button>
            </x-slot>
        @endif

        <style>
            .lq-plot text { fill: rgb(var(--gray-500)); font-size: 13px; }
            .lq-plot .axis { stroke: rgb(var(--gray-300)); }
            .lq-plot .grid { stroke: rgb(var(--gray-200)); }
            .lq-plot .whisker { stroke: rgb(var(--gray-500)); stroke-width: 2; }
            .lq-plot .box { fill: rgba(var(--primary-500), .14); stroke: rgb(var(--primary-600)); stroke-width: 2; }
            .lq-plot .median { stroke: rgb(var(--primary-700)); stroke-width: 3; stroke-linecap: round; }
            .lq-plot .ref-threshold { stroke: rgb(var(--warning-500)); stroke-width: 1.5; stroke-dasharray: 5 4; }
            .lq-plot .ref-norm { stroke: rgb(var(--gray-400)); stroke-width: 1.5; stroke-dasharray: 5 4; }
            .lq-plot .dot { fill: rgb(var(--primary-600)); stroke: #fff; stroke-width: 1.5; }
            .lq-plot .dot.low { fill: rgb(var(--warning-600)); }
            .lq-plot .hit:hover + .dot, .lq-plot .dot:hover { stroke: rgb(var(--gray-950)); stroke-width: 2; }
            .dark .lq-plot text { fill: rgb(var(--gray-400)); }
            .dark .lq-plot .axis { stroke: rgb(var(--gray-600)); }
            .dark .lq-plot .grid { stroke: rgb(var(--gray-800)); }
            .dark .lq-plot .whisker { stroke: rgb(var(--gray-400)); }
            .dark .lq-plot .box { fill: rgba(var(--primary-400), .18); stroke: rgb(var(--primary-400)); }
            .dark .lq-plot .median { stroke: rgb(var(--primary-300)); }
            .dark .lq-plot .dot { fill: rgb(var(--primary-400)); stroke: rgb(var(--gray-900)); }
            .dark .lq-plot .dot.low { fill: rgb(var(--warning-400)); }
            .dark .lq-plot .hit:hover + .dot { stroke: #fff; }
            /* Geschlecht: Palette-Slot 1/2 (validiertes Nachbarpaar) + Form als zweite Kodierung */
            .lq-plot .dot.g-w, .lq-legend .g-w { fill: #2a78d6; }
            .lq-plot .dot.g-m, .lq-legend .g-m { fill: #eb6834; }
            .lq-plot .dot.g-other, .lq-legend .g-other { fill: rgb(var(--gray-400)); }
            .dark .lq-plot .dot.g-w, .dark .lq-legend .g-w { fill: #3987e5; }
            .dark .lq-plot .dot.g-m, .dark .lq-legend .g-m { fill: #d95926; }
            .lq-legend { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; font-size: .875rem; margin-bottom: .25rem; }
            .lq-legend span { display: inline-flex; align-items: center; gap: .4rem; }
        </style>

        @if($s && $byGender)
            <div class="lq-legend text-gray-600 dark:text-gray-300">
                @foreach($data['groups'] as $g => $info)
                    <span>
                        <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">
                            @if($g === 'w')<circle class="g-w" cx="6" cy="6" r="5" />
                            @elseif($g === 'm')<rect class="g-m" x="1.5" y="1.5" width="9" height="9" rx="1" />
                            @else<rect class="g-other" x="2.5" y="2.5" width="7" height="7" transform="rotate(45 6 6)" />@endif
                        </svg>
                        {{ \App\Filament\Resources\TestRunResource\Widgets\TestRunLqBoxplot::GENDER_LABELS[$g] }}:
                        n = {{ $info['n'] }} · Median {{ $fmt($info['median']) }}
                    </span>
                @endforeach
            </div>
        @endif

        <svg class="lq-plot" viewBox="0 0 {{ $W }} 150" width="100%" role="img"
             aria-label="Boxplot der LQ-Werte{{ $s ? ': Median '.$fmt($s['median']).', Quartile '.$fmt($s['q1']).' bis '.$fmt($s['q3']) : '' }}"
             style="display:block; max-height:220px;">
            {{-- Raster + Achse --}}
            @for($t = $d0; $t <= $d1; $t += 10)
                <line class="grid" x1="{{ $x($t) }}" x2="{{ $x($t) }}" y1="28" y2="118" />
                <text x="{{ $x($t) }}" y="140" text-anchor="middle">{{ $t }}</text>
            @endfor
            <line class="axis" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="118" y2="118" />

            {{-- Referenzlinien --}}
            <line class="ref-threshold" x1="{{ $x($threshold) }}" x2="{{ $x($threshold) }}" y1="20" y2="118" />
            <text x="{{ $x($threshold) - 4 }}" y="14" text-anchor="end">auffällig &lt; {{ $threshold }}</text>
            <line class="ref-norm" x1="{{ $x($norm) }}" x2="{{ $x($norm) }}" y1="20" y2="118" />
            <text x="{{ $x($norm) + 4 }}" y="14">Normmittel {{ $norm }}</text>

            @if($s)
                <g>
                    <title>Median {{ $fmt($s['median']) }} · Q1 {{ $fmt($s['q1']) }} · Q3 {{ $fmt($s['q3']) }} · Whisker {{ $fmt($s['lo']) }}–{{ $fmt($s['hi']) }}</title>
                    {{-- Whisker --}}
                    <line class="whisker" x1="{{ $x($s['lo']) }}" x2="{{ $x($s['q1']) }}" y1="72" y2="72" />
                    <line class="whisker" x1="{{ $x($s['q3']) }}" x2="{{ $x($s['hi']) }}" y1="72" y2="72" />
                    <line class="whisker" x1="{{ $x($s['lo']) }}" x2="{{ $x($s['lo']) }}" y1="60" y2="84" />
                    <line class="whisker" x1="{{ $x($s['hi']) }}" x2="{{ $x($s['hi']) }}" y1="60" y2="84" />
                    {{-- Box + Median --}}
                    <rect class="box" x="{{ $x($s['q1']) }}" y="48" rx="4"
                          width="{{ max(2, $x($s['q3']) - $x($s['q1'])) }}" height="48" />
                    <line class="median" x1="{{ $x($s['median']) }}" x2="{{ $x($s['median']) }}" y1="48" y2="96" />
                </g>

                {{-- Einzelwerte (leicht gestreut, damit gleiche Werte sichtbar bleiben) --}}
                @foreach($data['values'] as $i => $v)
                    @php $cy = 72 + ((($i * 7) % 9) - 4) * 4.5; $cx = $x($v['lq']); @endphp
                    <g>
                        <title>{{ $v['label'] }}</title>
                        <circle class="hit" cx="{{ $cx }}" cy="{{ $cy }}" r="9" fill="transparent" />
                        @if(! $byGender)
                            <circle class="dot {{ $v['lq'] < $threshold ? 'low' : '' }}" cx="{{ $cx }}" cy="{{ $cy }}" r="4.5" />
                        @elseif($v['gender'] === 'w')
                            <circle class="dot g-w" cx="{{ $cx }}" cy="{{ $cy }}" r="5" />
                        @elseif($v['gender'] === 'm')
                            <rect class="dot g-m" x="{{ $cx - 4.5 }}" y="{{ $cy - 4.5 }}" width="9" height="9" rx="1" />
                        @else
                            <rect class="dot g-other" x="{{ $cx - 3.5 }}" y="{{ $cy - 3.5 }}" width="7" height="7"
                                  transform="rotate(45 {{ $cx }} {{ $cy }})" />
                        @endif
                    </g>
                @endforeach
            @endif
        </svg>
    </x-filament::section>
</x-filament-widgets::widget>
