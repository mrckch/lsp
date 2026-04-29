<x-filament-panels::page>
    @php
        $unlocked = app(\App\Domain\Crypto\CryptoService::class)->isUnlocked();
    @endphp

    @if ($unlocked)
        <x-filament::section>
            <x-slot name="heading">Status: entsperrt</x-slot>
            <p>Ihre Sitzung ist entsperrt. Klarnamen werden in den Listen angezeigt, wenn Ihre Berechtigungen es erlauben.</p>
            <div style="margin-top:1rem;">
                {{ $this->lockAction }}
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Klarnamen-Passwort eingeben</x-slot>
            <p style="margin-bottom:1rem;">
                Solange die Klarnamen gesperrt sind, sehen Sie nur Schülercodes statt Vor- und Nachnamen.
                Geben Sie Ihr persönliches Klarnamen-Passwort ein, um Klarnamen für diese Sitzung zu entsperren.
            </p>
            <form wire:submit="unlock">
                {{ $this->form }}
                <div style="margin-top:1rem;">
                    {{ $this->unlockAction }}
                </div>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
