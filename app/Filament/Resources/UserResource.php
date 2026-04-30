<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Auth\OnboardingService;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\PermissionResolver;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\UserResource\Pages;
use App\Jobs\SendWelcomeMailJob;
use App\Models\User;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'users.view'; }
    protected static function createPermission(): ?string { return 'users.manage'; }
    protected static function editPermission(): ?string { return 'users.manage'; }
    protected static function deletePermission(): ?string { return 'users.delete'; }

    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Verwaltung';
    protected static ?int $navigationSort = 10;
    protected static ?string $modelLabel = 'Benutzer';
    protected static ?string $pluralModelLabel = 'Benutzer';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Stammdaten')->columns(2)->schema([
                TextInput::make('username')->required()->maxLength(100)
                    ->regex('/^[a-zA-Z0-9._\-]+$/')->disabledOn('edit'),
                TextInput::make('display_name')->label('Anzeigename')->required()->maxLength(255),
                TextInput::make('email')->email()->maxLength(255),
                Toggle::make('is_active')->label('Aktiv')->default(true),
            ]),
            Section::make('Passwort')->schema([
                TextInput::make('password')->password()->revealable()
                    ->dehydrated(fn ($state) => filled($state))
                    ->minLength(12)
                    ->required(fn (string $context) => $context === 'create')
                    ->helperText('Beim Bearbeiten leer lassen, um das Passwort nicht zu ändern.'),
            ]),
            Section::make('Benutzerklassen')->schema([
                Select::make('userGroups')->label('Klassen')
                    ->multiple()->relationship('userGroups', 'name')
                    ->options(UserGroup::pluck('name', 'id'))
                    ->preload(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('username')->searchable()->sortable(),
                TextColumn::make('display_name')->label('Anzeigename')->searchable(),
                TextColumn::make('email'),
                TextColumn::make('userGroups.name')->label('Klassen')->badge()->limit(50),
                IconColumn::make('two_factor_enabled')->label('2FA')->boolean(),
                IconColumn::make('is_active')->label('Aktiv')->boolean(),
                TextColumn::make('last_login_at')->label('Letzter Login')->dateTime('d.m.Y H:i'),
            ])
            ->actions([
                EditAction::make(),
                Action::make('resetPassword')
                    ->label('Passwort zurücksetzen')->icon('heroicon-o-key')
                    ->form([
                        TextInput::make('new_password')->label('Neues Passwort')
                            ->password()->revealable()->required()->minLength(12),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update([
                            'password' => Hash::make($data['new_password']),
                            'password_changed_at' => now(),
                        ]);
                        app(PermissionResolver::class)->flush($record);
                        Notification::make()->success()->title('Passwort gesetzt')->send();
                    }),
                Action::make('sendWelcome')
                    ->label('Welcome-Mail senden')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn (User $r) => $r->email !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Erzeugt ein neues Initial-Passwort, markiert den User für '.
                        'Pflicht-Passwortwechsel beim nächsten Login und versendet eine Welcome-Mail.')
                    ->action(function (User $record) {
                        $onboarding = app(OnboardingService::class);
                        $plain = $onboarding->generateInitialPassword();
                        $onboarding->markForForcedChange($record, Hash::make($plain));

                        SendWelcomeMailJob::dispatch($record->id, $plain, auth()->id());

                        Notification::make()->success()
                            ->title('Welcome-Mail in der Queue')
                            ->body('User muss beim nächsten Login das Passwort ändern.')
                            ->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
