<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportJobResource\Pages;

use App\Domain\Import\Models\ImportDiffEntry;
use App\Domain\Import\Models\ImportJob;
use App\Filament\Resources\ImportJobResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewImportJob extends ViewRecord
{
    protected static string $resource = ImportJobResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Übersicht')->columns(3)->schema([
                TextEntry::make('started_at')->label('Gestartet')->dateTime('d.m.Y H:i'),
                TextEntry::make('committed_at')->label('Abgeschlossen')->dateTime('d.m.Y H:i')->placeholder('–'),
                TextEntry::make('status')->label('Status')->badge(),
                TextEntry::make('filename')->label('Datei'),
                TextEntry::make('source')->label('Quelle')
                    ->state(fn (ImportJob $record) => $record->import_source_id ? 'SVWS-API' : 'SchiLD-CSV'),
                TextEntry::make('schoolYear.label')->label('Schuljahr'),
                TextEntry::make('startedBy.display_name')->label('Gestartet von')->placeholder('–'),
            ]),

            Section::make('Ergebnis')->columns(5)->schema([
                TextEntry::make('c_imported')->label('Angelegt')
                    ->state(fn (ImportJob $record) => data_get($record->stats, 'committed.imported', 0)),
                TextEntry::make('c_updated')->label('Aktualisiert')
                    ->state(fn (ImportJob $record) => data_get($record->stats, 'committed.updated', 0)),
                TextEntry::make('c_archived')->label('Archiviert')
                    ->state(fn (ImportJob $record) => data_get($record->stats, 'committed.archived', 0)),
                TextEntry::make('c_skipped')->label('Übersprungen')
                    ->state(fn (ImportJob $record) => data_get($record->stats, 'committed.skipped', 0)),
                TextEntry::make('c_failed')->label('Fehlgeschlagen')
                    ->state(fn (ImportJob $record) => data_get($record->stats, 'committed.failed', 0)),
            ]),

            Section::make('Archivierte Schüler & Fehler')
                ->description('Einträge, die eine Nachkontrolle verdienen.')
                ->collapsible()
                ->schema([
                    TextEntry::make('review')->hiddenLabel()->listWithLineBreaks()->bulleted()
                        ->state(function (ImportJob $record) {
                            $entries = ImportDiffEntry::query()
                                ->where('import_job_id', $record->id)
                                ->whereIn('action', ['archive', 'error'])
                                ->orderBy('action')
                                ->orderBy('row_number')
                                ->get();

                            if ($entries->isEmpty()) {
                                return ['Keine Archivierungen oder Fehler.'];
                            }

                            return $entries->map(function (ImportDiffEntry $e) {
                                if ($e->action === 'error') {
                                    return sprintf('Fehler Zeile %s: %s', $e->row_number ?: '?', implode(', ', $e->errors ?? []));
                                }

                                return sprintf('Archiviert: ID %s (%s)', $e->external_student_id ?: '?', $e->commit_outcome ?? 'offen');
                            })->all();
                        }),
                ]),
        ]);
    }
}
