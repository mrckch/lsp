<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Meine letzten Aktivitäten</x-slot>
        <x-slot name="description">Erzeugte Dokumente und Mailversände der letzten Zeit. Aktualisiert sich alle 10s.</x-slot>

        @php $activities = $this->getActivities(10); @endphp

        @if($activities->isEmpty())
            <p style="color:#6b7280;">Noch keine Aktivitäten in diesem Account.</p>
        @else
            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                    <tr style="background:#f3f4f6;">
                        <th style="text-align:left; padding:0.4rem;">Zeit</th>
                        <th style="text-align:left; padding:0.4rem;">Typ</th>
                        <th style="text-align:left; padding:0.4rem;">Titel</th>
                        <th style="text-align:left; padding:0.4rem;">Detail</th>
                        <th style="text-align:left; padding:0.4rem;">Status</th>
                        <th style="text-align:left; padding:0.4rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($activities as $a)
                        <tr style="border-top:1px solid #e5e7eb;">
                            <td style="padding:0.4rem; white-space:nowrap; color:#6b7280;">
                                {{ optional($a['when'])->format('d.m. H:i') ?? '–' }}
                            </td>
                            <td style="padding:0.4rem;">
                                @if($a['kind'] === 'document')
                                    <span style="background:#dbeafe;color:#1e40af;padding:0.15rem 0.6rem;border-radius:9999px;font-size:0.8rem;">PDF/ZIP</span>
                                @elseif($a['kind'] === 'mail')
                                    <span style="background:#f3e8ff;color:#6b21a8;padding:0.15rem 0.6rem;border-radius:9999px;font-size:0.8rem;">Mail</span>
                                @else
                                    <span style="background:#fee2e2;color:#7f1d1d;padding:0.15rem 0.6rem;border-radius:9999px;font-size:0.8rem;">⚠ Fehler</span>
                                @endif
                            </td>
                            <td style="padding:0.4rem;">{{ $a['title'] }}</td>
                            <td style="padding:0.4rem; color:#6b7280;">{{ $a['subtitle'] }}</td>
                            <td style="padding:0.4rem;">
                                <span style="background:{{ $a['status_color'] }};color:#fff;padding:0.15rem 0.6rem;border-radius:9999px;font-size:0.8rem;">
                                    {{ $a['status'] }}
                                </span>
                                @if($a['includes_clearnames'])
                                    <span title="enthält Klarnamen" style="margin-left:0.4rem;color:#dc2626;">●</span>
                                @endif
                            </td>
                            <td style="padding:0.4rem;">
                                @if(! empty($a['url']))
                                    <a href="{{ $a['url'] }}" style="color:#2563eb; text-decoration:none;">→</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
