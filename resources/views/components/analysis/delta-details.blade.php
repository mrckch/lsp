{{--
    Veränderung je Schüler zwischen zwei Erhebungen: Δ-Boxplot je Gruppe, Kennzahlen, stärkste Verschlechterungen.
    dev = AnalysisReport::development(); names = Klarnamen zeigen (sonst Schülercode).
--}}
@props([
    'dev' => [],
    'print' => false,
    'names' => true,
    'limit' => 15,
])
@php
    $fmt = fn ($v, $d = 1) => $v === null ? '–' : number_format((float) $v, $d, ',', '.');
    $signed = fn ($v) => $v === null ? '–' : ($v > 0 ? '+' : '').$fmt($v);
    $cutText = 'Δ '.($dev['delta_cut_op'] === 'le' ? '≤' : '<').' '.$dev['delta_cut'];
    $groups = $dev['delta_groups'];
    $rowsTable = count($groups) > 1 ? [...$groups, $dev['delta_total'] + ['is_total' => true]] : $groups;
    $flaggedAll = collect($dev['declines'])->where('flagged', true);
    // Bildschirm: die stärksten $limit; Druck: alle auffälligen, mindestens $limit
    $declines = collect($dev['declines'])->take($print ? max($limit, $flaggedAll->count()) : $limit);
    $display = fn (array $r) => $names && $r['name'] !== '' && ! str_contains($r['name'], '***') ? $r['name'] : $r['student_code'];
@endphp

<div {{ $attributes->class(['an-delta', 'an-print' => $print]) }}>
    <x-analysis.tokens />
    <style>
        .an-delta h4 { font-weight: 600; margin: 1rem 0 .4rem; }
        .an-delta .an-flag { display: inline-block; padding: 0 .45rem; border-radius: 9999px; font-size: .75rem; background: rgba(var(--danger-500), .15); color: rgb(var(--danger-700)); }
        .dark .an-delta .an-flag { color: rgb(var(--danger-300)); }
        .an-delta.an-print .an-flag { background: #fee2e2; color: #991b1b; }
        .an-delta .neg { color: rgb(var(--danger-600)); font-weight: 600; }
        .an-delta .pos { color: rgb(var(--success-700)); }
        .an-delta.an-print .neg { color: #b91c1c; }
        .an-delta.an-print .pos { color: #15803d; }
        .an-delta .hint { font-size: .8rem; color: rgb(var(--gray-500)); }
        .an-delta.an-print .hint { color: #666; }
    </style>

    @if($dev['delta_total']['n'] === 0)
        <p class="hint">Keine Schüler:innen mit Ergebnissen in beiden Erhebungen ({{ $dev['from_label'] }} und {{ $dev['to_label'] }}).</p>
    @else
        <h4>Veränderung je Schüler:in ({{ $dev['from_label'] }} → {{ $dev['to_label'] }})</h4>
        <x-analysis.boxplot
            :groups="count($groups) > 1 ? $groups : [$dev['delta_total']]"
            :labels="count($groups) > 1"
            :domain="$dev['delta_domain']"
            :threshold="$dev['delta_cut']"
            :refs="[
                ['value' => 0, 'label' => 'keine Veränderung', 'class' => 'ref-norm', 'anchor' => 'start'],
                ['value' => $dev['delta_cut'], 'label' => 'auffällig '.$cutText, 'class' => 'ref-threshold', 'anchor' => 'end'],
            ]"
            unit="Δ-LQ"
            :print="$print"
        />
        <p class="hint">Punkte = Δ-LQ je Schüler:in (spätere minus frühere Erhebung), nur wer an beiden teilgenommen hat.</p>

        <div style="overflow-x:auto; margin-top:.5rem;">
            <table class="an-table">
                <thead>
                    <tr>
                        <th>Gruppe</th>
                        <th>n (beide)</th>
                        <th>MW Δ</th>
                        <th>Median Δ</th>
                        <th>verbessert</th>
                        <th>verschlechtert</th>
                        <th>auffällig ({{ $cutText }})</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rowsTable as $g)
                        <tr @class(['is-total' => $g['is_total'] ?? false])>
                            <td>{{ $g['label'] }}</td>
                            <td>{{ $g['n'] }}</td>
                            <td>{{ $signed($g['summary']['mean'] ?? null) }}</td>
                            <td>{{ $signed($g['summary']['median'] ?? null) }}</td>
                            <td>{{ $g['improved'] }}</td>
                            <td>{{ $g['declined'] }}</td>
                            <td>{{ $g['flagged'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <h4>Stärkste Verschlechterungen</h4>
        @if($declines->isEmpty())
            <p class="hint">Niemand hat sich verschlechtert.</p>
        @else
            <div style="overflow-x:auto;">
                <table class="an-table">
                    <thead>
                        <tr>
                            <th>{{ $names ? 'Name' : 'Schülercode' }}</th>
                            <th style="text-align:left;">Klasse</th>
                            <th>{{ $dev['from_label'] }}</th>
                            <th>{{ $dev['to_label'] }}</th>
                            <th>Δ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($declines as $r)
                            <tr>
                                <td>
                                    @if(! $print)
                                        <a href="{{ route('filament.admin.resources.students.view', ['record' => $r['student_id']]) }}" style="color: rgb(var(--primary-600));">{{ $display($r) }}</a>
                                    @else
                                        {{ $display($r) }}
                                    @endif
                                </td>
                                <td style="text-align:left;">{{ $r['learning_group_name'] }}</td>
                                <td>{{ $r['lq_from'] }}</td>
                                <td>{{ $r['lq'] }}</td>
                                <td class="neg">{{ $r['delta'] }}</td>
                                <td>@if($r['flagged'])<span class="an-flag">auffällig</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(count($dev['declines']) > $declines->count())
                <p class="hint">{{ $declines->count() }} von {{ count($dev['declines']) }} Verschlechterungen gezeigt ({{ $flaggedAll->count() }} auffällig).</p>
            @endif
        @endif
    @endif
</div>
