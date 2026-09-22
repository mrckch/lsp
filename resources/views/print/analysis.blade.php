<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Datenanalyse Lese-Screening</title>
    <style>
        @page { size: A4 {{ $orientation }}; margin: 14mm 14mm 18mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, "Segoe UI", Roboto, Arial, sans-serif; font-size: 10pt; color: #222; margin: 0;
               -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        header.doc { border-bottom: 2px solid #2563eb; padding-bottom: 6pt; margin-bottom: 10pt; }
        header.doc .school { font-size: 9pt; color: #555; }
        h1 { font-size: 16pt; margin: 2pt 0 4pt; }
        h2 { font-size: 12.5pt; margin: 14pt 0 6pt; }
        h3 { font-size: 11pt; margin: 12pt 0 4pt; }
        .meta { font-size: 9pt; color: #444; line-height: 1.4; }
        .kpis { display: flex; gap: 8pt; margin: 8pt 0 4pt; }
        .kpi { flex: 1; border: 1px solid #ddd; border-radius: 4pt; padding: 5pt 7pt; }
        .kpi .v { font-size: 14pt; font-weight: 600; }
        .kpi .l { font-size: 8.5pt; color: #555; }
        .page-break { break-before: page; }
        .list { width: 100%; border-collapse: collapse; font-size: 9pt; font-variant-numeric: tabular-nums; }
        .list th, .list td { border-bottom: 1px solid #ddd; padding: 2.5pt 5pt; text-align: left; }
        .list th.num, .list td.num { text-align: right; }
        .list thead th { background: #f3f3f3; }
        .list tr { break-inside: avoid; }
        .group-block { break-inside: avoid-page; }
        .sev { display: inline-block; padding: 0 5pt; border-radius: 8pt; font-size: 8pt; }
        .sev-foerderbedarf { background: #fee2e2; color: #991b1b; }
        .sev-auffaellig { background: #fef3c7; color: #92400e; }
        .sev-hinweis { background: #dbeafe; color: #1e40af; }
        .sev-none { color: #555; }
        .note { font-size: 8.5pt; color: #666; margin-top: 4pt; }
    </style>
</head>
<body>
    <header class="doc">
        <div class="school">{{ $schoolName }}</div>
        <h1>Datenanalyse Lese-Screening</h1>
        <div class="meta">
            <div><strong>Filter:</strong> {{ $filterText }}</div>
            <div><strong>Erstellt:</strong> {{ $createdAt }} von {{ $createdBy }}</div>
        </div>
    </header>

    <div class="kpis">
        <div class="kpi"><div class="v">{{ $kpis['students'] }}</div><div class="l">Schüler:innen ({{ $kpis['attempts'] }} Versuche)</div></div>
        <div class="kpi"><div class="v">{{ $kpis['median'] === null ? '–' : number_format($kpis['median'], 1, ',', '') }}</div><div class="l">Median LQ</div></div>
        @foreach($kpis['shares'] as $share)
            <div class="kpi"><div class="v">{{ $share['pct'] }} %</div><div class="l">{{ $share['label'] }} ({{ $share['count'] }})</div></div>
        @endforeach
    </div>

    <h2>Vergleich der Gruppen (LQ)</h2>
    <x-analysis.boxplot
        :groups="$dist['groups']"
        :labels="true"
        :domain="$dist['domain']"
        :bands="$dist['bands']"
        :threshold="$dist['threshold']"
        :threshold-label="$dist['threshold_label']"
        :show-bands="$showBands"
        :by-gender="$byGender"
        :gender-info="$genderInfo"
        :print="true"
    />
    <p class="note">Box = mittlere 50 % (Q1–Q3), Strich = Median, Linien = Whisker (1,5 × IQR), Punkte = einzelne Schüler:innen.</p>

    <h3>Kennzahlen</h3>
    <x-analysis.stats-table :groups="$dist['groups']" :total="$dist['total']" :bands="$dist['bands']" :print="true" />

    @if($studentLists !== [])
        <div class="page-break"></div>
        <h2>Schülerlisten je Gruppe</h2>
        <p class="note">Sortiert nach LQ aufsteigend – Schüler:innen mit Förderbedarf stehen oben.</p>
        @foreach($studentLists as $list)
            <div class="group-block">
                <h3>{{ $list['label'] }} <span style="font-weight:normal; color:#666;">(n = {{ count($list['students']) }})</span></h3>
                <table class="list">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Klasse</th>
                            <th>Geschlecht</th>
                            <th>Testdurchlauf</th>
                            <th class="num">LQ</th>
                            <th class="num">Rohwert</th>
                            <th>Förderbereich</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($list['students'] as $s)
                            <tr>
                                <td>{{ $s['name'] !== '' ? $s['name'] : $s['student_code'] }}</td>
                                <td>{{ $s['learning_group_name'] }}</td>
                                <td>{{ $s['gender_label'] }}</td>
                                <td>{{ $s['test_run_name'] }}</td>
                                <td class="num"><strong>{{ $s['lq'] }}</strong></td>
                                <td class="num">{{ $s['raw'] ?? '–' }}</td>
                                <td><span class="sev sev-{{ $s['severity'] }}">{{ $s['severity_label'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</body>
</html>
