<table style="width:100%; font-size:0.9rem; border-collapse:collapse;">
    <thead>
        <tr style="text-align:left; border-bottom:1px solid rgba(127,127,127,.3);">
            <th style="padding:0.4rem 0.3rem;">#</th>
            <th style="padding:0.4rem 0.3rem;">Status</th>
            <th style="padding:0.4rem 0.3rem;">Angemeldet</th>
            <th style="padding:0.4rem 0.3rem;">Abgabe</th>
            <th style="padding:0.4rem 0.3rem;">Antw.</th>
            <th style="padding:0.4rem 0.3rem;">Rohwert</th>
            <th style="padding:0.4rem 0.3rem;">LQ</th>
        </tr>
    </thead>
    <tbody>
        @foreach($attempts as $a)
            <tr style="border-bottom:1px solid rgba(127,127,127,.15); vertical-align:top;">
                <td style="padding:0.4rem 0.3rem;">{{ $a->id }}</td>
                <td style="padding:0.4rem 0.3rem;">
                    {{ $statusLabels[$a->status] ?? $a->status }}
                    @if($a->ended_by && $a->status !== 'zurueckgesetzt')
                        <div style="font-size:0.8rem; opacity:0.7;">durch {{ $a->ended_by }}</div>
                    @endif
                    @if($a->status === 'zurueckgesetzt')
                        <div style="font-size:0.8rem; opacity:0.7;">
                            von {{ $a->resetBy?->display_name ?? $a->resetBy?->username ?? '–' }}:
                            {{ $a->reset_reason ?? '–' }}
                        </div>
                    @endif
                </td>
                <td style="padding:0.4rem 0.3rem;">{{ $a->started_at?->format('d.m. H:i') ?? '–' }}</td>
                <td style="padding:0.4rem 0.3rem;">{{ $a->submitted_at?->format('d.m. H:i') ?? '–' }}</td>
                <td style="padding:0.4rem 0.3rem;">{{ $a->answers_count }}</td>
                <td style="padding:0.4rem 0.3rem;">{{ in_array($a->status, ['abgegeben', 'zeit_abgelaufen'], true) ? $a->score_raw : '–' }}</td>
                <td style="padding:0.4rem 0.3rem;">{{ $a->lq_current ?? '–' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
