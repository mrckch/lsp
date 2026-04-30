<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Permission\Models\Permission;
use App\Domain\Permission\Models\UserGroup;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\UserGroupResource\Pages;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserGroupResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'user_groups.manage'; }
    protected static function createPermission(): ?string { return 'user_groups.manage'; }
    protected static function editPermission(): ?string { return 'user_groups.manage'; }
    protected static function deletePermission(): ?string { return 'user_groups.manage'; }

    protected static ?string $model = UserGroup::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Verwaltung';
    protected static ?int $navigationSort = 20;
    protected static ?string $modelLabel = 'Benutzerklasse';
    protected static ?string $pluralModelLabel = 'Benutzerklassen';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Klasse')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(100)->columnSpan(2),
                Textarea::make('description')->rows(2)->columnSpan(2),
                TextInput::make('sort_order')->numeric()->default(0),
                Toggle::make('force_two_factor')->label('2FA für diese Klasse erzwingen'),
                Toggle::make('is_system')->label('System-Klasse')->disabled(),
            ]),
            Section::make('Permissions')
                ->description('Mitglieder dieser Klasse erhalten alle hier ausgewählten Berechtigungen.')
                ->schema([
                    Select::make('permissions')
                        ->label('Berechtigungen')
                        ->multiple()
                        ->relationship('permissions', 'key')
                        ->options(fn () => Permission::query()
                            ->orderBy('area')->orderBy('key')
                            ->get()
                            ->mapWithKeys(fn ($p) => [$p->id => "[$p->area] $p->key — $p->description"])
                            ->all())
                        ->searchable()
                        ->preload(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('users_count')->label('User')->counts('users'),
                TextColumn::make('permissions_count')->label('Permissions')->counts('permissions'),
                IconColumn::make('force_two_factor')->label('2FA-Pflicht')->boolean(),
                IconColumn::make('is_system')->label('System')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (UserGroup $r) => ! $r->is_system),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserGroups::route('/'),
            'create' => Pages\CreateUserGroup::route('/create'),
            'edit' => Pages\EditUserGroup::route('/{record}/edit'),
        ];
    }
}
