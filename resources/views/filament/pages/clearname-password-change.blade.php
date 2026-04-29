<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Klarnamen-Passwort ändern</x-slot>
        <p style="margin-bottom:1rem;">
            Mit Ihrem Klarnamen-Passwort werden die Schülerklarnamen für Ihre Sitzung entschlüsselt.
            Ein Wechsel des Passworts wirkt sich nur auf Ihren Account aus – andere Benutzer bleiben unberührt.
        </p>
        <form wire:submit="change">
            {{ $this->form }}
            <div style="margin-top:1rem;">
                {{ $this->changeAction }}
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
