<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">2-Faktor-Bestätigung</x-slot>
        <x-slot name="description">
            Für Ihr Konto ist die Zwei-Faktor-Authentifizierung aktiviert. Bitte bestätigen Sie den
            aktuellen Code aus Ihrer Authenticator-App, um fortzufahren.
        </x-slot>

        <form wire:submit="verify">
            {{ $this->form }}
            <div style="margin-top:1rem; display:flex; gap:0.75rem; align-items:center;">
                {{ $this->verifyAction }}
                <a href="/admin/logout" style="color:#6b7280;">Abmelden</a>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
