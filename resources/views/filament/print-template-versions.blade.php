@php /** @var \App\Domain\PrintTemplate\Models\PrintTemplate $template */ @endphp
<div style="max-height:60vh; overflow-y:auto;">
    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="background:#f3f4f6;">
                <th style="text-align:left; padding:0.5rem;">Version</th>
                <th style="text-align:left; padding:0.5rem;">Erstellt</th>
                <th style="text-align:left; padding:0.5rem;">User</th>
                <th style="text-align:left; padding:0.5rem;">Aktuell</th>
            </tr>
        </thead>
        <tbody>
            @foreach($template->versions as $v)
                <tr style="border-top:1px solid #e5e7eb;">
                    <td style="padding:0.5rem;">v{{ $v->version_number }}</td>
                    <td style="padding:0.5rem;">{{ $v->created_at?->format('d.m.Y H:i') }}</td>
                    <td style="padding:0.5rem;">{{ $v->created_by_user_id ?? '–' }}</td>
                    <td style="padding:0.5rem;">{{ $template->current_version_id === $v->id ? '✓' : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
