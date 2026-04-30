<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\TestRun\Models\AssessmentType;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\AssessmentTypeResource\Pages;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssessmentTypeResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'assessment_types.manage'; }
    protected static function createPermission(): ?string { return 'assessment_types.manage'; }
    protected static function editPermission(): ?string { return 'assessment_types.manage'; }
    protected static function deletePermission(): ?string { return 'assessment_types.manage'; }

    protected static ?string $model = AssessmentType::class;
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Test-Konfiguration';
    protected static ?int $navigationSort = 5;
    protected static ?string $modelLabel = 'Erhebungstyp';
    protected static ?string $pluralModelLabel = 'Erhebungstypen';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('key')->label('Schlüssel')->required()->maxLength(50)
                ->helperText('Technischer Bezeichner, z. B. "eingangstest"'),
            TextInput::make('label')->label('Bezeichnung')->required()->maxLength(100),
            TextInput::make('sort_order')->label('Sortierung')->numeric()->default(0),
            Toggle::make('is_active')->label('Aktiv')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->sortable()->searchable(),
                TextColumn::make('key')->label('Schlüssel')->searchable(),
                TextColumn::make('sort_order')->label('Sort.'),
                IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssessmentTypes::route('/'),
            'create' => Pages\CreateAssessmentType::route('/create'),
            'edit' => Pages\EditAssessmentType::route('/{record}/edit'),
        ];
    }
}
