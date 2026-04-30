<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\NoticeText\Models\NoticeText;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\NoticeTextResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NoticeTextResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'notice_texts.view'; }
    protected static function createPermission(): ?string { return 'notice_texts.manage'; }
    protected static function editPermission(): ?string { return 'notice_texts.manage'; }
    protected static function deletePermission(): ?string { return 'notice_texts.manage'; }

    protected static ?string $model = NoticeText::class;
    protected static ?string $navigationIcon = 'heroicon-o-information-circle';
    protected static ?string $navigationGroup = 'Test-Konfiguration';
    protected static ?int $navigationSort = 50;
    protected static ?string $modelLabel = 'Hinweistext';
    protected static ?string $pluralModelLabel = 'Hinweistexte';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(150),
            Textarea::make('content')->label('Hinweis für die Schüler-Sicht')
                ->rows(8)->required(),
            Select::make('status')->options(['entwurf' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert'])
                ->default('aktiv')->required(),
            Toggle::make('is_default')->label('Standard'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                BadgeColumn::make('status')->colors(['success' => 'aktiv', 'warning' => 'entwurf', 'gray' => 'archiviert']),
                IconColumn::make('is_default')->label('Standard')->boolean(),
            ])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNoticeTexts::route('/'),
            'create' => Pages\CreateNoticeText::route('/create'),
            'edit' => Pages\EditNoticeText::route('/{record}/edit'),
        ];
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
