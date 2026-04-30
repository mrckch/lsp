<x-filament-panels::page>
    @if($newRecoveryKey !== null)
        <x-filament::section>
            <x-slot name="heading">⚠ Neuer Recovery-Key — einmalige Anzeige</x-slot>
            <x-slot name="description">
                Bitte verwahren Sie diesen Key SOFORT an einem sicheren Ort
                (Passwort-Manager, Tresor, …). Er wird nirgends gespeichert
                und kann nicht erneut angezeigt werden.
            </x-slot>
            <pre style="background:#fff3cd; padding:1rem; font-size:1.1rem; user-select:all; word-break:break-all;">{{ $newRecoveryKey }}</pre>
            <div style="margin-top:1rem;">
                {{ $this->dismissKeyAction }}
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Recovery-Key-Verwaltung</x-slot>
        <x-slot name="description">
            Recovery-Keys können benutzt werden, um den Klarnamen-Zugriff wiederherzustellen,
            wenn alle Klarnamen-Passwörter verloren sind. Sie werden nie im Klartext gespeichert,
            nur Fingerprint und Status sind sichtbar.
        </x-slot>

        <div style="margin-bottom:1rem;">
            {{ $this->regenerateAction }}
        </div>

        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:1px solid #ccc; text-align:left;">
                    <th style="padding:0.5rem;">Label</th>
                    <th style="padding:0.5rem;">Fingerprint</th>
                    <th style="padding:0.5rem;">Erstellt</th>
                    <th style="padding:0.5rem;">Status</th>
                    <th style="padding:0.5rem;">Verbraucht</th>
                    <th style="padding:0.5rem;">Widerrufen</th>
                </tr>
            </thead>
            <tbody>
                @forelse($this->getRecoveryKeys() as $row)
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:0.5rem;">{{ $row['label'] }}</td>
                        <td style="padding:0.5rem; font-family:monospace;">{{ $row['fingerprint_short'] }}</td>
                        <td style="padding:0.5rem;">{{ $row['created_at'] }}</td>
                        <td style="padding:0.5rem;">
                            @if($row['status'] === 'active')
                                <span style="color:#16a34a;">aktiv</span>
                            @elseif($row['status'] === 'used')
                                <span style="color:#ca8a04;">verbraucht</span>
                            @else
                                <span style="color:#6b7280;">widerrufen</span>
                            @endif
                        </td>
                        <td style="padding:0.5rem;">{{ $row['used_at'] ?? '–' }}</td>
                        <td style="padding:0.5rem;">{{ $row['revoked_at'] ?? '–' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:1rem; text-align:center; color:#6b7280;">Keine Recovery-Keys vorhanden.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
