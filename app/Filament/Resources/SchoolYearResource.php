<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\School\Models\SchoolYear;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\SchoolYearResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SchoolYearResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'school_years.view'; }
    protected static function createPermission(): ?string { return 'school_years.manage'; }
    protected static function editPermission(): ?string { return 'school_years.manage'; }
    protected static function deletePermission(): ?string { return 'school_years.manage'; }

    protected static ?string $model = SchoolYear::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Stammdaten';
    protected static ?int $navigationSort = 10;
    protected static ?string $modelLabel = 'Schuljahr';
    protected static ?string $pluralModelLabel = 'Schuljahre';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('label')->label('Bezeichnung')->required()->maxLength(20)->placeholder('z. B. 2026/27'),
            DatePicker::make('start_date')->label('Beginn')->required(),
            DatePicker::make('end_date')->label('Ende')->required(),
            Toggle::make('is_active')->label('Aktiv')->default(true),
            Toggle::make('is_archived')->label('Archiviert')->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Schuljahr')->sortable()->searchable(),
                TextColumn::make('start_date')->label('Beginn')->date('d.m.Y'),
                TextColumn::make('end_date')->label('Ende')->date('d.m.Y'),
                IconColumn::make('is_active')->label('Aktiv')->boolean(),
                IconColumn::make('is_archived')->label('Archiv')->boolean(),
            ])
            ->defaultSort('start_date', 'desc')
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchoolYears::route('/'),
            'create' => Pages\CreateSchoolYear::route('/create'),
            'edit' => Pages\EditSchoolYear::route('/{record}/edit'),
        ];
    }
}
