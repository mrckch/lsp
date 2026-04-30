<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\SvwsApiClient;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\ImportSourceResource\Pages;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImportSourceResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string
    {
        return 'import.sources.manage';
    }

    protected static function createPermission(): ?string
    {
        return 'import.sources.manage';
    }

    protected static function editPermission(): ?string
    {
        return 'import.sources.manage';
    }

    protected static function deletePermission(): ?string
    {
        return 'import.sources.manage';
    }

    protected static ?string $model = ImportSource::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 25;

    protected static ?string $modelLabel = 'Importquelle';

    protected static ?string $pluralModelLabel = 'Importquellen';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Quelle')->columns(2)->schema([
                TextInput::make('key')->required()->maxLength(50)
                    ->helperText('Eindeutiger Schlüssel, z. B. "svws_main".'),
                TextInput::make('name')->required()->maxLength(100),
                Select::make('type')->required()->default('svws_api')
                    ->options(['svws_api' => 'SVWS-NRW-API']),
                Toggle::make('is_active')->label('Aktiv')->default(true),
            ]),
            Section::make('SVWS-Verbindung')
                ->description('Wird verschlüsselt in config_encrypted gespeichert (Laravel-AppKey).')
                ->statePath('config_encrypted')
                ->columns(2)
                ->schema([
                    TextInput::make('api_url')->label('API-URL')
                        ->required()->url()
                        ->placeholder('https://svws-server.local')
                        ->helperText('Basis-URL ohne /db-Pfad.'),
                    TextInput::make('schema')->label('DB-Schema')
                        ->required()->maxLength(50)
                        ->placeholder('svwsdb'),
                    TextInput::make('username')->label('Benutzername')
                        ->required()->maxLength(100),
                    TextInput::make('password')->label('Passwort')
                        ->password()->revealable()
                        ->required(fn (string $context) => $context === 'create')
                        ->helperText('Beim Bearbeiten leer lassen, um nicht zu ändern.')
                        ->dehydrated(fn ($state) => filled($state)),
                    Toggle::make('verify_ssl')->label('SSL-Zertifikat prüfen')
                        ->default(true)
                        ->helperText('Bei selbst-signierten Zertifikaten ggf. deaktivieren.'),
                    TextInput::make('timeout_seconds')->label('Timeout (Sek.)')
                        ->numeric()->default(20)->minValue(5)->maxValue(120),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->sortable()->searchable(),
                TextColumn::make('name')->searchable(),
                BadgeColumn::make('type'),
                IconColumn::make('is_active')->label('Aktiv')->boolean(),
                TextColumn::make('updated_at')->label('Geändert')->dateTime('d.m.Y H:i'),
            ])
            ->actions([
                EditAction::make(),
                Action::make('testConnection')
                    ->label('Verbindung testen')
                    ->icon('heroicon-o-bolt')
                    ->color('info')
                    ->action(function (ImportSource $record) {
                        try {
                            $info = (new SvwsApiClient($record))->fetchSchoolInfo();
                            Notification::make()->success()
                                ->title('Verbindung OK')
                                ->body('Schule: '.($info['bezeichnung1'] ?? 'unbekannt').
                                    ', aktueller Abschnitt: '.($info['idSchuljahresabschnitt'] ?? '?'))
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()
                                ->title('Verbindung fehlgeschlagen')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportSources::route('/'),
            'create' => Pages\CreateImportSource::route('/create'),
            'edit' => Pages\EditImportSource::route('/{record}/edit'),
        ];
    }
}
