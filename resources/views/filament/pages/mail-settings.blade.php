<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        <div style="margin-top:1rem; display:flex; gap:0.5rem;">
            {{ $this->saveAction }}
            {{ $this->testMailAction }}
        </div>
    </form>
</x-filament-panels::page>
