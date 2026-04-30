<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">2-Faktor-Authentifizierung einrichten – Pflicht</x-slot>
        <x-slot name="description">
            Für Ihre Benutzerklasse ist 2FA verpflichtend. Bitte richten Sie jetzt Ihre Authenticator-App ein,
            bevor Sie weitere Funktionen nutzen können.
        </x-slot>

        @if($qrSvg === null)
            <p>Klicken Sie auf <em>Einrichtung starten</em>, um einen QR-Code anzuzeigen.</p>
            <div style="margin-top:1rem;">{{ $this->startAction }}</div>
        @else
            <div style="display:flex; gap:2rem; flex-wrap:wrap; align-items:flex-start;">
                <div>
                    <p><strong>1.</strong> Scannen Sie den QR-Code in Ihrer Authenticator-App:</p>
                    <div style="margin-top:0.5rem;">{!! $qrSvg !!}</div>
                </div>
                <div style="flex:1; min-width:280px;">
                    <p><strong>2.</strong> Manuell eintragen, falls Scannen nicht möglich ist:</p>
                    <pre style="background:#f5f5f5; padding:0.5rem; user-select:all;">{{ $secret }}</pre>
                    <p style="margin-top:1rem;"><strong>3.</strong> Geben Sie den 6-stelligen Code aus der App ein:</p>
                    <form wire:submit="confirm">
                        {{ $this->form }}
                        <div style="margin-top:1rem;">{{ $this->confirmAction }}</div>
                    </form>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
