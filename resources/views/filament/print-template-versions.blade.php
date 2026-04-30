@php /** @var \App\Domain\PrintTemplate\Models\PrintTemplate $template */ @endphp
<div style="max-height:65vh; overflow-y:auto;">
    <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
        <thead>
            <tr style="background:#f3f4f6;">
                <th style="text-align:left; padding:0.5rem;">Version</th>
                <th style="text-align:left; padding:0.5rem;">Erstellt am</th>
                <th style="text-align:left; padding:0.5rem;">User-ID</th>
                <th style="text-align:left; padding:0.5rem;">HTML-Größe</th>
                <th style="text-align:left; padding:0.5rem;">CSS-Größe</th>
                <th style="text-align:left; padding:0.5rem;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($template->versions as $v)
                @php
                    $isCurrent = $template->current_version_id === $v->id;
                    $htmlSize = strlen($v->html_content ?? '');
                    $cssSize = strlen($v->css_content ?? '');
                @endphp
                <tr style="border-top:1px solid #e5e7eb; {{ $isCurrent ? 'background:#ecfdf5;' : '' }}">
                    <td style="padding:0.5rem; font-weight:600;">v{{ $v->version_number }}</td>
                    <td style="padding:0.5rem;">{{ $v->created_at?->format('d.m.Y H:i') }}</td>
                    <td style="padding:0.5rem;">{{ $v->created_by_user_id ?? '–' }}</td>
                    <td style="padding:0.5rem;">{{ number_format($htmlSize, 0, ',', '.') }} B</td>
                    <td style="padding:0.5rem;">{{ number_format($cssSize, 0, ',', '.') }} B</td>
                    <td style="padding:0.5rem;">
                        @if($isCurrent)
                            <span style="background:#16a34a; color:#fff; padding:0.15rem 0.5rem; border-radius:4px; font-size:0.8rem;">aktuell</span>
                        @else
                            <span style="color:#6b7280;">älter</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin-top:1rem; font-size:0.85rem; color:#6b7280;">
        Bei jedem Speichern mit geändertem Inhalt wird automatisch eine neue Version erzeugt.
        Frühere Versionen bleiben für Reproduzierbarkeit bereits versendeter Rückmeldungen erhalten.
    </p>
</div>
