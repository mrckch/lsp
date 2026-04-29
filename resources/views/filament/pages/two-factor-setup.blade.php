<x-filament-panels::page>
    @if (auth()->user()->two_factor_enabled)
        <x-filament::section>
            <x-slot name="heading">2FA ist aktiv</x-slot>
            <p>Die Zwei-Faktor-Authentifizierung ist für Ihren Account aktiviert.</p>
            <div style="margin-top:1rem;">
                {{ $this->disableAction }}
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">2FA einrichten</x-slot>
            @if ($qrSvg === null)
                <p style="margin-bottom:1rem;">
                    Mit 2FA schützen Sie Ihren Account zusätzlich. Sie benötigen eine Authenticator-App
                    (z. B. Google Authenticator, FreeOTP, Aegis, 1Password).
                </p>
                {{ $this->startAction }}
            @else
                <p>Scannen Sie den folgenden QR-Code mit Ihrer Authenticator-App und geben Sie anschließend einen Code ein.</p>
                <div style="margin:1rem 0;">{!! $qrSvg !!}</div>
                <p>Falls der QR-Code nicht funktioniert, geben Sie diesen Schlüssel manuell ein:</p>
                <code style="display:block; padding:0.5rem; background:#f3f4f6; border-radius:4px; margin:0.5rem 0 1rem;">{{ $secret }}</code>
                <form wire:submit="confirm">
                    {{ $this->form }}
                    <div style="margin-top:1rem;">{{ $this->confirmAction }}</div>
                </form>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
