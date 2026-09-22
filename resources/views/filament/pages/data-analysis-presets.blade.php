@if($presets->isEmpty())
    <p style="color:rgb(var(--gray-500));">Noch keine gespeicherten Auswertungen. Über „Auswertungen → Speichern …“ lassen sich Filter und Ansicht ablegen.</p>
@else
    <ul style="display:flex; flex-direction:column; gap:.5rem;">
        @foreach($presets as $preset)
            @php $own = $preset->user_id === auth()->id(); @endphp
            <li style="display:flex; align-items:center; gap:.75rem; justify-content:space-between; border:1px solid rgba(var(--gray-500), .2); border-radius:.5rem; padding:.5rem .75rem;">
                <div>
                    <div style="font-weight:600;">{{ $preset->name }}</div>
                    <div style="font-size:.8rem; color:rgb(var(--gray-500));">
                        {{ \App\Domain\Analytics\AnalysisPdfRenderer::VIEWS[$preset->settings['view'] ?? 'vergleich'] ?? 'Vergleich' }}
                        · {{ $own ? 'eigene' : 'von '.($preset->user->display_name ?? $preset->user->username ?? '?') }}
                        @if($preset->is_shared) · freigegeben @endif
                        · {{ $preset->updated_at?->format('d.m.Y') }}
                    </div>
                </div>
                <div style="display:flex; gap:.5rem;">
                    <x-filament::button size="sm" wire:click="loadPreset({{ $preset->id }})">Laden</x-filament::button>
                    @if($own)
                        <x-filament::button size="sm" color="danger" outlined
                                            wire:click="deletePreset({{ $preset->id }})"
                                            wire:confirm="Auswertung „{{ $preset->name }}“ löschen?">
                            Löschen
                        </x-filament::button>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
