<x-filament-panels::page>
    @php
        $student = $this->record;
        $recent = $this->getRecentAttempts(5);
        $chart = $this->getMiniChart();
        $enrollments = $this->getEnrollmentTimeline();
    @endphp

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
        <x-filament::section>
            <x-slot name="heading">Stammdaten</x-slot>
            <table style="width:100%; font-size:0.95rem;">
                <tbody>
                    <tr><td style="padding:0.3rem 0;">Schülercode:</td>
                        <td style="font-family:ui-monospace,monospace;"><strong>{{ $student->student_code }}</strong></td></tr>
                    <tr><td style="padding:0.3rem 0;">Vorname:</td>
                        <td>{{ $student->first_name_encrypted }}</td></tr>
                    <tr><td style="padding:0.3rem 0;">Nachname:</td>
                        <td>{{ $student->last_name_encrypted }}</td></tr>
                    <tr><td style="padding:0.3rem 0;">Geschlecht:</td>
                        <td>{{ ['m'=>'männlich','w'=>'weiblich','d'=>'divers','unbekannt'=>'unbekannt'][$student->gender] ?? $student->gender }}</td></tr>
                    <tr><td style="padding:0.3rem 0;">Externe ID:</td>
                        <td>{{ $student->external_student_id ?? '–' }}
                            <span style="color:#6b7280;">({{ $student->external_id_source }})</span></td></tr>
                    <tr><td style="padding:0.3rem 0;">Status:</td>
                        <td>
                            @if($student->status === 'aktiv')
                                <span style="background:#dcfce7;color:#166534;padding:0.1rem 0.6rem;border-radius:9999px;">aktiv</span>
                            @else
                                <span style="background:#f3f4f6;color:#374151;padding:0.1rem 0.6rem;border-radius:9999px;">archiviert</span>
                            @endif
                            @if($student->archived_at)
                                <span style="color:#6b7280; font-size:0.85rem;">seit {{ $student->archived_at->format('d.m.Y') }}</span>
                            @endif
                        </td></tr>
                    @if($student->archived_reason)
                        <tr><td style="padding:0.3rem 0;">Archivgrund:</td>
                            <td style="color:#6b7280;">{{ $student->archived_reason }}</td></tr>
                    @endif
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">LQ-Verlauf (kompakt)</x-slot>
            @if($chart === null)
                <p style="color:#6b7280;">Noch keine abgegebenen Versuche.</p>
            @else
                <div style="position:relative; height:160px;">
                    <canvas id="miniLqChart"></canvas>
                </div>
                <p style="font-size:0.8rem; color:#6b7280; margin-top:0.5rem;">
                    {{ count($chart['labels']) }} Erhebungen ·
                    aktueller LQ:
                    @php $last = end($chart['lq']); @endphp
                    <strong style="color:{{ $last === null ? '#6b7280' : ($last < 70 ? '#dc2626' : ($last < 85 ? '#ea580c' : '#0f172a')) }};">
                        {{ $last ?? '–' }}
                    </strong>
                </p>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Letzte Erhebungen</x-slot>
        @if(empty($recent))
            <p style="color:#6b7280;">Noch keine abgegebenen Versuche.</p>
        @else
            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                    <tr style="background:#f3f4f6;">
                        <th style="text-align:left; padding:0.4rem;">Datum</th>
                        <th style="text-align:left; padding:0.4rem;">Erhebung</th>
                        <th style="text-align:left; padding:0.4rem;">Typ</th>
                        <th style="text-align:left; padding:0.4rem;">Schuljahr</th>
                        <th style="text-align:left; padding:0.4rem;">Form</th>
                        <th style="text-align:right; padding:0.4rem;">Rohwert</th>
                        <th style="text-align:right; padding:0.4rem;">LQ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recent as $r)
                        <tr style="border-top:1px solid #e5e7eb;">
                            <td style="padding:0.4rem;">{{ $r['date'] }}</td>
                            <td style="padding:0.4rem;">{{ $r['test_run'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $r['assessment_type'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $r['school_year'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $r['parallel_form'] ?? '–' }}</td>
                            <td style="padding:0.4rem; text-align:right;">{{ $r['score_raw'] }}</td>
                            <td style="padding:0.4rem; text-align:right; font-weight:600;
                                       color:{{ $r['lq'] === null ? '#6b7280' : ($r['lq'] < 70 ? '#dc2626' : ($r['lq'] < 85 ? '#ea580c' : '#0f172a')) }};">
                                {{ $r['lq'] ?? '–' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Schullaufbahn</x-slot>
        @if(empty($enrollments))
            <p style="color:#6b7280;">Keine Enrollments hinterlegt.</p>
        @else
            <table style="width:100%; font-size:0.9rem; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f3f4f6;">
                        <th style="text-align:left; padding:0.4rem;">Schuljahr</th>
                        <th style="text-align:left; padding:0.4rem;">Stufe</th>
                        <th style="text-align:left; padding:0.4rem;">von</th>
                        <th style="text-align:left; padding:0.4rem;">bis</th>
                        <th style="text-align:left; padding:0.4rem;">Wiederholer</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($enrollments as $e)
                        <tr style="border-top:1px solid #e5e7eb;">
                            <td style="padding:0.4rem;">{{ $e['school_year'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $e['grade'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $e['from'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $e['to'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $e['is_repeater'] ? 'ja' : 'nein' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    @if($chart !== null)
        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
            <script>
                (function() {
                    const labels = @json($chart['labels']);
                    const lqs = @json($chart['lq']);
                    const ctx = document.getElementById('miniLqChart');
                    if (!ctx || typeof Chart === 'undefined') return;
                    new Chart(ctx, {
                        type: 'line',
                        data: { labels, datasets: [{
                            data: lqs, borderColor: '#2563eb',
                            backgroundColor: 'rgba(37,99,235,0.1)',
                            tension: 0.25, pointRadius: 3, borderWidth: 2, spanGaps: true,
                        }]},
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
                            scales: {
                                y: { suggestedMin: 50, suggestedMax: 150, ticks: { stepSize: 25 } },
                                x: { ticks: { maxRotation: 0 } }
                            }
                        }
                    });
                })();
            </script>
        @endpush
    @endif
</x-filament-panels::page>
