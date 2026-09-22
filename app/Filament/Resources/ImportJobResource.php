<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Import\Models\ImportJob;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\ImportJobResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only Import-Verlauf: jeder Import-Lauf (Dry-Run + Commit) bleibt mit
 * Statistik, Datei, Nutzer und Zeitpunkt dauerhaft einsehbar.
 */
class ImportJobResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string
    {
        return 'import.run';
    }

    protected static ?string $model = ImportJob::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Import-Lauf';

    protected static ?string $pluralModelLabel = 'Import-Verlauf';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->label('Gestartet')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('filename')->label('Datei')->limit(30)->tooltip(fn ($state) => $state),
                TextColumn::make('source')->label('Quelle')
                    ->getStateUsing(fn (ImportJob $record) => $record->import_source_id ? 'SVWS-API' : 'SchiLD-CSV')
                    ->badge(),
                TextColumn::make('schoolYear.label')->label('Schuljahr'),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'committed' => 'success',
                        'committing' => 'warning',
                        'aborted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('result')->label('Ergebnis (A/U/Arch/Skip/Fehler)')
                    ->getStateUsing(function (ImportJob $record) {
                        $c = data_get($record->stats, 'committed');
                        if (! is_array($c)) {
                            return '–';
                        }

                        return sprintf(
                            '%d / %d / %d / %d / %d',
                            $c['imported'] ?? 0, $c['updated'] ?? 0, $c['archived'] ?? 0,
                            $c['skipped'] ?? 0, $c['failed'] ?? 0,
                        );
                    }),
                TextColumn::make('startedBy.display_name')->label('Von')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'validated' => 'Validiert',
                    'diff_ready' => 'Diff bereit',
                    'committing' => 'Läuft',
                    'committed' => 'Abgeschlossen',
                    'aborted' => 'Abgebrochen',
                ]),
            ])
            ->actions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportJobs::route('/'),
            'view' => Pages\ViewImportJob::route('/{record}'),
        ];
    }
}
