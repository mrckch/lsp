<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Backup\BackupRunner;
use App\Domain\Backup\Models\BackupTarget;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\BackupTargetResource\Pages;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BackupTargetResource extends Resource
{
    use AuthorizedResource;

    /** Zugangsdaten, die beim Bearbeiten nicht vorbefüllt und bei leerer Eingabe beibehalten werden. */
    public const SECRET_CONFIG_KEYS = ['password', 'private_key', 'passphrase'];

    protected static function viewPermission(): ?string
    {
        return 'system.backup.targets.manage';
    }

    protected static function createPermission(): ?string
    {
        return 'system.backup.targets.manage';
    }

    protected static function editPermission(): ?string
    {
        return 'system.backup.targets.manage';
    }

    protected static function deletePermission(): ?string
    {
        return 'system.backup.targets.manage';
    }

    protected static ?string $model = BackupTarget::class;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Backup-Ziel';

    protected static ?string $pluralModelLabel = 'Backup-Ziele';

    public static function form(Form $form): Form
    {
        $isSftp = fn (Get $get): bool => $get('type') === 'sftp';

        return $form->schema([
            Section::make('Ziel')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(100),
                Select::make('type')->label('Typ')->required()->default('local')->live()
                    ->options(['local' => 'Nur lokal (auf dieser VM)', 'sftp' => 'SFTP (externer Server)'])
                    ->helperText('Lokale Backups liegen auf derselben VM – für den Notfall ist ein externes Ziel nötig.'),
            ]),
            Section::make('SFTP-Verbindung')
                ->description('Jedes Backup wird verschlüsselt lokal abgelegt und zusätzlich hochgeladen.')
                ->visible($isSftp)
                ->columns(2)
                ->schema([
                    TextInput::make('config_encrypted.host')->label('Host')->required($isSftp)->maxLength(255),
                    TextInput::make('config_encrypted.port')->label('Port')->numeric()->default(22)
                        ->minValue(1)->maxValue(65535),
                    TextInput::make('config_encrypted.username')->label('Benutzer')->required($isSftp)->maxLength(100),
                    TextInput::make('config_encrypted.root')->label('Zielverzeichnis')
                        ->placeholder('/backups/lsp')->maxLength(255),
                    TextInput::make('config_encrypted.password')->label('Passwort')
                        ->password()->revealable()
                        ->formatStateUsing(fn () => null)
                        ->helperText('Beim Bearbeiten leer lassen, um nicht zu ändern.'),
                    TextInput::make('config_encrypted.host_fingerprint')->label('Host-Fingerprint (optional)')
                        ->helperText('Schützt vor untergeschobenen Servern, z. B. aus ssh-keyscan.'),
                    Textarea::make('config_encrypted.private_key')->label('Privater SSH-Schlüssel (statt Passwort)')
                        ->rows(4)->columnSpanFull()
                        ->formatStateUsing(fn () => null)
                        ->helperText('Beim Bearbeiten leer lassen, um nicht zu ändern.'),
                    TextInput::make('config_encrypted.passphrase')->label('Schlüssel-Passphrase')
                        ->password()->revealable()
                        ->formatStateUsing(fn () => null),
                ]),
            Section::make('Verschlüsselung')
                ->description('Backup-Inhalte werden vor dem Speichern und Upload mit AES-256-GCM (Argon2id-KEK) verschlüsselt. Ohne Passwort wird kein Backup erstellt.')
                ->schema([
                    TextInput::make('encryption_password')->label('Backup-Passwort')
                        ->password()->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->minLength(12)
                        ->helperText('Mindestens 12 Zeichen, getrennt vom Recovery-Key verwahren. Beim Bearbeiten leer lassen, um nicht zu ändern.'),
                ]),
            Section::make('Retention')->columns(3)->schema([
                TextInput::make('retention_daily')->label('Tägliche behalten')->numeric()->default(7),
                TextInput::make('retention_weekly')->label('Wöchentliche behalten')->numeric()->default(4),
                TextInput::make('retention_monthly')->label('Monatliche behalten')->numeric()->default(12),
            ]),
            Toggle::make('is_active')->label('Aktiv (tägliches Backup)')->default(true),
        ]);
    }

    /**
     * Leere Geheimnis-Felder beim Bearbeiten bedeuten „unverändert".
     */
    public static function keepUnchangedSecrets(array $data, BackupTarget $record): array
    {
        if (! isset($data['config_encrypted']) || ! is_array($data['config_encrypted'])) {
            return $data;
        }

        foreach (self::SECRET_CONFIG_KEYS as $key) {
            $previous = $record->config($key);
            if (blank($data['config_encrypted'][$key] ?? null) && filled($previous)) {
                $data['config_encrypted'][$key] = $previous;
            }
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable(),
                BadgeColumn::make('type'),
                TextColumn::make('runs_count')->label('Backups')->counts('runs'),
                TextColumn::make('retention_daily')->label('Daily'),
                TextColumn::make('retention_weekly')->label('Weekly'),
                TextColumn::make('retention_monthly')->label('Monthly'),
                IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->actions([
                EditAction::make(),
                Action::make('testConnection')
                    ->label('Verbindung testen')->icon('heroicon-o-signal')
                    ->visible(fn (BackupTarget $record): bool => $record->type !== 'local')
                    ->action(function (BackupTarget $record) {
                        try {
                            app(BackupRunner::class)->testConnection($record);
                            Notification::make()->success()
                                ->title('Verbindung OK')
                                ->body('Testdatei wurde geschrieben und wieder gelöscht.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()
                                ->title('Verbindung fehlgeschlagen')
                                ->body($e->getMessage())->persistent()->send();
                        }
                    }),
                Action::make('runNow')
                    ->label('Jetzt sichern')->icon('heroicon-o-play')
                    ->requiresConfirmation()
                    ->action(function (BackupTarget $record) {
                        $run = app(BackupRunner::class)->run($record, 'manual', auth()->id());
                        if ($run->status === 'success') {
                            Notification::make()->success()
                                ->title("Backup OK: {$run->file_name}")->send();
                        } else {
                            Notification::make()->danger()
                                ->title('Backup fehlgeschlagen')
                                ->body($run->error_message ?? '')->send();
                        }
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBackupTargets::route('/'),
            'create' => Pages\CreateBackupTarget::route('/create'),
            'edit' => Pages\EditBackupTarget::route('/{record}/edit'),
        ];
    }
}
