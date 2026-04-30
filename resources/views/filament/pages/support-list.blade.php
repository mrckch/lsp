<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Filter</x-slot>
        {{ $this->form }}
    </x-filament::section>

    @php $rows = $this->getRows(); @endphp

    <x-filament::section>
        <x-slot name="heading">{{ count($rows) }} Treffer</x-slot>
        <x-slot name="headerEnd">
            <div style="display:flex; gap:0.5rem;">
                {{ $this->exportCsvAction }}
                {{ $this->exportPdfAction }}
                {{ $this->mailListAction }}
            </div>
        </x-slot>

        @if(empty($rows))
            <p style="color:#6b7280;">Keine Schüler erfüllen die aktiven Schwellen mit den gewählten Filtern.</p>
        @else
            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                    <tr style="background:#f3f4f6;">
                        <th style="text-align:left; padding:0.4rem;">Code</th>
                        <th style="text-align:left; padding:0.4rem;">Name</th>
                        <th style="text-align:left; padding:0.4rem;">Klasse</th>
                        <th style="text-align:left; padding:0.4rem;">Stufe</th>
                        <th style="text-align:left; padding:0.4rem;">Letzter Test</th>
                        <th style="text-align:right; padding:0.4rem;">LQ</th>
                        <th style="text-align:left; padding:0.4rem;">Schweregrad</th>
                        <th style="text-align:left; padding:0.4rem;">Schwelle</th>
                        <th style="text-align:left; padding:0.4rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php
                            $sevColor = match($row['severity']) {
                                'foerderbedarf' => '#dc2626',
                                'auffaellig' => '#ea580c',
                                'hinweis' => '#2563eb',
                                default => '#6b7280',
                            };
                            $sevLabel = match($row['severity']) {
                                'foerderbedarf' => 'Förderbedarf',
                                'auffaellig' => 'auffällig',
                                'hinweis' => 'Hinweis',
                                default => $row['severity'],
                            };
                        @endphp
                        <tr style="border-top:1px solid #e5e7eb;">
                            <td style="padding:0.4rem; font-family:ui-monospace,monospace;">{{ $row['student_code'] }}</td>
                            <td style="padding:0.4rem;">{{ $row['student_name'] }}</td>
                            <td style="padding:0.4rem;">{{ $row['group'] }}</td>
                            <td style="padding:0.4rem;">{{ $row['grade_level'] }}</td>
                            <td style="padding:0.4rem;">{{ $row['date'] }}</td>
                            <td style="padding:0.4rem; text-align:right; font-weight:600;
                                       color:{{ $row['lq'] === null ? '#6b7280' : ($row['lq'] < 70 ? '#dc2626' : ($row['lq'] < 85 ? '#ea580c' : '#0f172a')) }};">
                                {{ $row['lq'] ?? '–' }}
                            </td>
                            <td style="padding:0.4rem;">
                                <span style="background:{{ $sevColor }};color:#fff;padding:0.15rem 0.6rem;border-radius:9999px;font-size:0.8rem;">
                                    {{ $sevLabel }}
                                </span>
                            </td>
                            <td style="padding:0.4rem; color:#374151;">{{ $row['threshold_name'] }}</td>
                            <td style="padding:0.4rem;">
                                <a href="{{ route('filament.admin.resources.students.view', ['record' => $row['student_id']]) }}"
                                   style="color:#2563eb; text-decoration:none;">
                                    Detail →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
