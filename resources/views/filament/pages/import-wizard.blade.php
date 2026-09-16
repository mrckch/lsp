<x-filament-panels::page>
    <form wire:submit="analyze">
        {{ $this->form }}
        <div style="margin-top:1rem; display:flex; gap:0.5rem;">
            <button type="submit" wire:loading.attr="disabled"
                    style="background:#2563eb; color:#fff; padding:0.5rem 1rem; border:0; border-radius:6px; cursor:pointer; font-weight:600;">
                Analysieren (Dry-Run)
            </button>
            @if($jobId)
                <button type="button" wire:click="cancel"
                        style="background:#6b7280; color:#fff; padding:0.5rem 1rem; border:0; border-radius:6px; cursor:pointer;">
                    Analyse verwerfen
                </button>
            @endif
        </div>
    </form>

    @if($committing)
        <x-filament::section wire:poll.700ms="processCommitChunk">
            <x-slot name="heading">Import läuft …</x-slot>
            <x-slot name="description">Die Seite bitte geöffnet lassen, bis der Import abgeschlossen ist.</x-slot>

            @php $pct = $total > 0 ? (int) floor($processed / $total * 100) : 0; @endphp
            <div style="margin-bottom:0.5rem; font-weight:600;">
                {{ number_format($processed, 0, ',', '.') }} von {{ number_format($total, 0, ',', '.') }} verarbeitet ({{ $pct }} %)
            </div>
            <div style="width:100%; height:1.25rem; background:#e5e7eb; border-radius:9999px; overflow:hidden;">
                <div style="width:{{ $pct }}%; height:100%; background:#16a34a; transition:width 0.3s ease;"></div>
            </div>
        </x-filament::section>
    @endif

    @php $entries = $committing ? collect() : $this->getDiffEntries(); @endphp

    @if($entries->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">2. Diff-Analyse – Admin-Entscheidung</x-slot>
            <x-slot name="description">
                Pro Zeile: <strong>Bestätigen</strong> (Aktion ausführen) oder <strong>Ausschließen</strong> (überspringen).
                Klick auf den Status wechselt die Entscheidung.
            </x-slot>

            <div style="margin-bottom:1rem; display:flex; gap:1rem; flex-wrap:wrap;">
                @php
                    $by = $entries->groupBy('action')->map->count();
                @endphp
                <span style="background:#dbeafe; color:#1e40af; padding:0.25rem 0.75rem; border-radius:9999px;">
                    Anlage: {{ $by['create'] ?? 0 }}
                </span>
                <span style="background:#fef3c7; color:#92400e; padding:0.25rem 0.75rem; border-radius:9999px;">
                    Update: {{ $by['update'] ?? 0 }}
                </span>
                <span style="background:#fee2e2; color:#991b1b; padding:0.25rem 0.75rem; border-radius:9999px;">
                    Archiv: {{ $by['archive'] ?? 0 }}
                </span>
                <span style="background:#f3f4f6; color:#374151; padding:0.25rem 0.75rem; border-radius:9999px;">
                    Skip: {{ $by['skip'] ?? 0 }}
                </span>
                <span style="background:#fecaca; color:#7f1d1d; padding:0.25rem 0.75rem; border-radius:9999px;">
                    Fehler: {{ $by['error'] ?? 0 }}
                </span>
            </div>

            <div style="max-height:60vh; overflow-y:auto; border:1px solid #e5e7eb; border-radius:6px;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead style="position:sticky; top:0; background:#f9fafb;">
                        <tr>
                            <th style="text-align:left; padding:0.5rem;">Zeile</th>
                            <th style="text-align:left; padding:0.5rem;">Aktion</th>
                            <th style="text-align:left; padding:0.5rem;">SchiLD-ID</th>
                            <th style="text-align:left; padding:0.5rem;">Daten / Begründung</th>
                            <th style="text-align:left; padding:0.5rem;">Entscheidung</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($entries as $entry)
                            @php
                                $color = match($entry->action) {
                                    'create' => '#dbeafe',
                                    'update' => '#fef3c7',
                                    'archive' => '#fee2e2',
                                    'skip' => '#f3f4f6',
                                    'error' => '#fecaca',
                                    default => '#fff',
                                };
                                $row = $entry->payload['row'] ?? null;
                            @endphp
                            <tr style="border-top:1px solid #e5e7eb; background:{{ $color }};">
                                <td style="padding:0.5rem;">{{ $entry->row_number ?: '–' }}</td>
                                <td style="padding:0.5rem; font-weight:600;">{{ $entry->action }}</td>
                                <td style="padding:0.5rem;">{{ $entry->external_student_id ?: '–' }}</td>
                                <td style="padding:0.5rem; font-size:0.9rem;">
                                    @if($entry->action === 'archive')
                                        <em>{{ $entry->payload['reason'] ?? 'kein Grund' }}</em>
                                    @elseif($entry->action === 'error')
                                        <strong style="color:#7f1d1d;">{{ implode(', ', $entry->errors ?? []) }}</strong>
                                    @elseif($row)
                                        {{ $row['first_name'] ?? '?' }} {{ $row['last_name'] ?? '?' }}
                                        ({{ $row['gender'] ?? '?' }}) – Klasse {{ $row['group_name'] ?? '?' }}
                                    @endif
                                </td>
                                <td style="padding:0.5rem;">
                                    <button type="button"
                                            wire:click="toggleEntry({{ $entry->id }})"
                                            style="background:{{ $entry->admin_decision === 'confirm' ? '#16a34a' : '#dc2626' }};
                                                   color:#fff; padding:0.25rem 0.75rem; border:0; border-radius:4px; cursor:pointer;">
                                        {{ $entry->admin_decision === 'confirm' ? '✓ bestätigt' : '✗ ausgeschlossen' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1rem;">
                @unless($this->clearnameUnlocked())
                    <div style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:0.75rem 1rem; border-radius:6px; margin-bottom:0.75rem;">
                        <strong>Klarnamen-Session ist gesperrt.</strong> Der Import verschlüsselt die Schülernamen und
                        benötigt die entsperrte Session. Bitte links unter <strong>Klarnamen&nbsp;→&nbsp;Entsperren</strong>
                        entsperren und danach „Import durchführen" klicken.
                    </div>
                @endunless
                <div style="display:flex; gap:0.5rem;">
                    <button type="button" wire:click="commit" wire:loading.attr="disabled"
                            style="background:#16a34a; color:#fff; padding:0.5rem 1rem; border:0; border-radius:6px; cursor:pointer; font-weight:600;">
                        Import durchführen
                    </button>
                    <button type="button" wire:click="cancel"
                            style="background:#6b7280; color:#fff; padding:0.5rem 1rem; border:0; border-radius:6px; cursor:pointer;">
                        Analyse verwerfen
                    </button>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
