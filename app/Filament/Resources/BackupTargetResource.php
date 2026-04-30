<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Backup\BackupRunner;
use App\Domain\Backup\Models\BackupTarget;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\BackupTargetResource\Pages;
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

class BackupTargetResource extends Resource
{
    use AuthorizedResource;

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
        return $form->schema([
            Section::make('Ziel')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(100),
                Select::make('type')->required()->default('local')
                    ->options(['local' => 'Lokal', 'sftp' => 'SFTP', 's3' => 'S3-kompatibel']),
            ]),
            Section::make('Verschlüsselung')
                ->description('Backup-Inhalte werden vor Upload mit AES-256-GCM (Argon2id-KEK) verschlüsselt.')
                ->schema([
                    TextInput::make('encryption_password')->label('Backup-Passwort')
                        ->password()->revealable()
                        ->helperText('Beim Bearbeiten leer lassen, um nicht zu ändern.'),
                ]),
            Section::make('Retention')->columns(3)->schema([
                TextInput::make('retention_daily')->label('Tägliche behalten')->numeric()->default(7),
                TextInput::make('retention_weekly')->label('Wöchentliche behalten')->numeric()->default(4),
                TextInput::make('retention_monthly')->label('Monatliche behalten')->numeric()->default(12),
            ]),
            Toggle::make('is_active')->label('Aktiv')->default(true),
        ]);
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
