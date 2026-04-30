<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Passwort ändern – Pflicht beim ersten Login</x-slot>
        <x-slot name="description">
            Sie haben ein Initial-Passwort erhalten. Bitte vergeben Sie jetzt ein eigenes Passwort,
            bevor Sie weitere Funktionen nutzen können.
        </x-slot>

        <form wire:submit="change">
            {{ $this->form }}
            <div style="margin-top:1rem;">
                {{ $this->changeAction }}
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
