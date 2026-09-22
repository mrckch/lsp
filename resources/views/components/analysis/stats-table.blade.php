{{--
    Kennzahlentabelle je Gruppe + Zeile „Gesamt“.
    groups/total wie AnalysisReport::groupedDistribution(); bands für die Spalten „% Förderbereich“.
--}}
@props([
    'groups' => [],
    'total' => null,
    'bands' => [],
    'print' => false,
    'drilldown' => false,
])
@php
    $fmt = fn ($v, $d = 1) => $v === null ? '–' : number_format((float) $v, $d, ',', '.');
    // Förderbereiche außer „unauffällig“ als Prozentspalten
    $sevCols = collect($bands)->where('severity', '!=', 'none')->values();
    $rows = $total !== null && count($groups) > 1 ? [...$groups, $total + ['is_total' => true]] : $groups;
@endphp

<div {{ $attributes->class(['an-table-wrap', 'an-print' => $print]) }} style="overflow-x:auto;">
    <style>
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
    <table class="an-table">
        <thead>
            <tr>
                <th>Gruppe</th>
                <th>n</th>
                <th>MW</th>
                <th>SD</th>
                <th>Median</th>
                <th>Q1</th>
                <th>Q3</th>
                <th>Min</th>
                <th>Max</th>
                @foreach($sevCols as $b)
                    <th>% {{ $b['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $g)
                @php $s = $g['summary']; $label = trim($g['label'].($g['sublabel'] ?? null ? ' · '.$g['sublabel'] : '')); @endphp
                <tr @class(['is-total' => $g['is_total'] ?? false])>
                    <td>
                        @if($drilldown && ! $print && ! ($g['is_total'] ?? false))
                            <button type="button" class="an-group" wire:click="mountAction('groupStudents', { group: @js($g['key']) })">{{ $label }}</button>
                        @else
                            {{ $label }}
                        @endif
                        @if($g['too_small'] && ! ($g['is_total'] ?? false))
                            <span class="muted" title="Weniger als {{ \App\Domain\Analytics\AnalysisReport::MIN_GROUP_SIZE }} Werte – Kennzahlen kaum aussagekräftig">(klein)</span>
                        @endif
                    </td>
                    <td>{{ $g['n'] }}</td>
                    <td>{{ $fmt($s['mean'] ?? null) }}</td>
                    <td>{{ $fmt($s['sd'] ?? null) }}</td>
                    <td>{{ $fmt($s['median'] ?? null) }}</td>
                    <td>{{ $fmt($s['q1'] ?? null) }}</td>
                    <td>{{ $fmt($s['q3'] ?? null) }}</td>
                    <td>{{ $fmt($s['min'] ?? null, 0) }}</td>
                    <td>{{ $fmt($s['max'] ?? null, 0) }}</td>
                    @foreach($sevCols as $b)
                        @php $c = $g['band_counts'][$b['severity']] ?? 0; @endphp
                        <td>{{ $g['n'] > 0 ? $fmt($c / $g['n'] * 100, 0).' %' : '–' }} <span class="muted">({{ $c }})</span></td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
