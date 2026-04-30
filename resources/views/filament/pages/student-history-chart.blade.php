<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Schüler auswählen</x-slot>
        {{ $this->form }}
    </x-filament::section>

    @php $data = $this->getChartData(); @endphp

    @if($data === null)
        <p style="color:#6b7280; margin-top:1rem;">Bitte oben einen Schüler auswählen, um den Verlauf zu sehen.</p>
    @elseif(empty($data['labels']))
        <x-filament::section>
            <x-slot name="heading">Keine Daten</x-slot>
            <p>Für {{ $data['student']->student_code }} sind noch keine abgegebenen Versuche vorhanden.</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Lesequotient-Verlauf</x-slot>
            <x-slot name="description">
                {{ $data['student']->student_code }} – {{ $data['student']->first_name_encrypted }} {{ $data['student']->last_name_encrypted }}
                · {{ count($data['labels']) }} Erhebungen
            </x-slot>

            <div style="position:relative; height:380px;">
                <canvas id="lqChart"></canvas>
            </div>

            <div style="margin-top:1rem; font-size:0.85rem; color:#6b7280;">
                Schwellen: <span style="color:#ea580c;">LQ &lt; 85 (auffällig)</span> ·
                <span style="color:#dc2626;">LQ &lt; 70 (Förderbedarf)</span> ·
                Norm-Mittel: 100
            </div>

            <div style="margin-top:1rem;">
                {{ $this->exportAction }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Alle Erhebungen</x-slot>
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
                    @foreach($data['labels'] as $i => $label)
                        @php $lq = $data['lq'][$i]; @endphp
                        <tr style="border-top:1px solid #e5e7eb;">
                            <td style="padding:0.4rem;">{{ $label }}</td>
                            <td style="padding:0.4rem;">{{ $data['meta'][$i]['test_run'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $data['meta'][$i]['assessment_type'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $data['meta'][$i]['school_year'] ?? '–' }}</td>
                            <td style="padding:0.4rem;">{{ $data['meta'][$i]['parallel_form'] ?? '–' }}</td>
                            <td style="padding:0.4rem; text-align:right;">{{ $data['raw'][$i] }}</td>
                            <td style="padding:0.4rem; text-align:right; font-weight:600;
                                       color:{{ $lq === null ? '#6b7280' : ($lq < 70 ? '#dc2626' : ($lq < 85 ? '#ea580c' : '#0f172a')) }};">
                                {{ $lq ?? '–' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>

        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
            <script>
                (function() {
                    const labels = @json($data['labels']);
                    const lqs = @json($data['lq']);
                    const ctx = document.getElementById('lqChart');
                    if (!ctx || typeof Chart === 'undefined') return;

                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'LQ',
                                data: lqs,
                                borderColor: '#2563eb',
                                backgroundColor: 'rgba(37,99,235,0.12)',
                                borderWidth: 2,
                                tension: 0.25,
                                pointRadius: 5,
                                pointHoverRadius: 7,
                                spanGaps: true,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: { mode: 'index', intersect: false },
                            },
                            scales: {
                                y: {
                                    suggestedMin: 50, suggestedMax: 150,
                                    title: { display: true, text: 'Lesequotient' },
                                    grid: {
                                        color: function(ctx) {
                                            const v = ctx.tick?.value;
                                            if (v === 100) return '#9ca3af';
                                            if (v === 85)  return '#fed7aa';
                                            if (v === 70)  return '#fecaca';
                                            return '#e5e7eb';
                                        },
                                        lineWidth: function(ctx) {
                                            const v = ctx.tick?.value;
                                            return (v === 100 || v === 85 || v === 70) ? 1.5 : 1;
                                        }
                                    }
                                },
                                x: { title: { display: true, text: 'Erhebungsdatum' } }
                            }
                        }
                    });
                })();
            </script>
        @endpush
    @endif
</x-filament-panels::page>
