<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\PrintJob\Models\GeneratedDocument;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\GeneratedDocumentResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Übersicht der asynchron erzeugten Dokumente (Bulk-Jobs etc.).
 * Read-only + Download/Delete pro Eintrag.
 */
class GeneratedDocumentResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'print.download'; }
    protected static function deletePermission(): ?string { return 'print.templates.manage'; }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    protected static ?string $model = GeneratedDocument::class;
    protected static ?string $navigationIcon = 'heroicon-o-folder';
    protected static ?string $navigationGroup = 'Drucksachen';
    protected static ?int $navigationSort = 20;
    protected static ?string $modelLabel = 'Erzeugtes Dokument';
    protected static ?string $pluralModelLabel = 'Erzeugte Dokumente';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Erstellt')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('file_name')->label('Datei')->searchable(),
                TextColumn::make('mime_type')->label('Typ')->badge(),
                TextColumn::make('size_bytes')->label('Größe')
                    ->formatStateUsing(fn (int $s) => self::formatBytes($s)),
                IconColumn::make('includes_clearnames')->label('Klarnamen')->boolean(),
                TextColumn::make('expires_at')->label('Läuft ab')->date('d.m.Y'),
                TextColumn::make('createdBy.username')->label('Erstellt von'),
            ])
            ->filters([
                Filter::make('includes_clearnames')
                    ->label('Nur mit Klarnamen')
                    ->query(fn (Builder $q) => $q->where('includes_clearnames', true))
                    ->toggle(),
            ])
            ->actions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->action(function (GeneratedDocument $record) {
                        if (! Storage::disk('local')->exists($record->file_path)) {
                            \Filament\Notifications\Notification::make()->danger()
                                ->title('Datei nicht mehr vorhanden')->send();

                            return null;
                        }
                        $bytes = Storage::disk('local')->get($record->file_path);

                        return response()->streamDownload(
                            fn () => print ($bytes),
                            $record->file_name,
                            ['Content-Type' => $record->mime_type],
                        );
                    }),
                DeleteAction::make()
                    ->after(fn (GeneratedDocument $record) => Storage::disk('local')->delete($record->file_path)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGeneratedDocuments::route('/'),
        ];
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1024 / 1024, 1).' MB';
    }
}
