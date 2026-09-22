@if($group === null)
    <p style="color:rgb(var(--gray-500));">Diese Gruppe ist mit den aktuellen Filtern nicht mehr vorhanden.</p>
@else
    <style>
        .da-list { width: 100%; border-collapse: collapse; font-size: .875rem; font-variant-numeric: tabular-nums; }
        .da-list th, .da-list td { padding: .4rem .5rem; border-bottom: 1px solid rgb(var(--gray-200)); text-align: left; }
        .da-list th { font-weight: 600; color: rgb(var(--gray-600)); }
        .da-list .num { text-align: right; }
        .da-list a { color: rgb(var(--primary-600)); }
        .dark .da-list th, .dark .da-list td { border-color: rgb(var(--gray-700)); }
        .dark .da-list th { color: rgb(var(--gray-300)); }
        .da-sev { display: inline-block; padding: 0 .5rem; border-radius: 9999px; font-size: .75rem; }
        .da-sev.sev-foerderbedarf { background: rgba(var(--danger-500), .15); color: rgb(var(--danger-700)); }
        .da-sev.sev-auffaellig { background: rgba(var(--warning-500), .18); color: rgb(var(--warning-700)); }
        .da-sev.sev-hinweis { background: rgba(var(--info-500), .15); color: rgb(var(--info-700)); }
        .dark .da-sev.sev-foerderbedarf { color: rgb(var(--danger-300)); }
        .dark .da-sev.sev-auffaellig { color: rgb(var(--warning-300)); }
        .dark .da-sev.sev-hinweis { color: rgb(var(--info-300)); }
    </style>
    <table class="da-list">
        <thead>
            <tr>
                <th>Name</th>
                <th>Klasse</th>
                <th>Geschlecht</th>
                <th class="num">LQ</th>
                <th class="num">Rohwert</th>
                <th>Förderbereich</th>
            </tr>
        </thead>
        <tbody>
            @foreach($group['students'] as $s)
                <tr>
                    <td>
                        <a href="{{ route('filament.admin.resources.students.view', ['record' => $s['student_id']]) }}">
                            {{ $s['name'] !== '' && ! str_contains($s['name'], '***') ? $s['name'] : $s['student_code'] }}
                        </a>
                    </td>
                    <td>{{ $s['learning_group_name'] }}</td>
                    <td>{{ $s['gender_label'] }}</td>
                    <td class="num"><strong>{{ $s['lq'] }}</strong></td>
                    <td class="num">{{ $s['raw'] ?? '–' }}</td>
                    <td><span class="da-sev sev-{{ $s['severity'] }}">{{ $s['severity_label'] }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
