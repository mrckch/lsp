<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Permission\ScopeFilter;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\LearningGroupResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LearningGroupResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string
    {
        return 'learning_groups.view';
    }

    protected static function createPermission(): ?string
    {
        return 'learning_groups.manage';
    }

    protected static function editPermission(): ?string
    {
        return 'learning_groups.manage';
    }

    protected static function deletePermission(): ?string
    {
        return 'learning_groups.manage';
    }

    protected static ?string $model = LearningGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Lerngruppe';

    protected static ?string $pluralModelLabel = 'Lerngruppen';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('school_year_id')->label('Schuljahr')
                ->options(SchoolYear::query()->orderByDesc('start_date')->pluck('label', 'id'))
                ->required(),
            TextInput::make('name')->required()->maxLength(50),
            Select::make('group_type')->label('Typ')
                ->options(['klasse' => 'Klasse', 'kurs' => 'Kurs'])
                ->default('klasse')->required(),
            TextInput::make('grade_level')->label('Klassenstufe')->maxLength(10)->placeholder('5, 6, ..., EF'),
            Textarea::make('description')->label('Beschreibung')->rows(2),
            Toggle::make('is_active')->label('Aktiv')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $q) => app(ScopeFilter::class)->applyToLearningGroups($q, auth()->user()))
            ->columns([
                TextColumn::make('schoolYear.label')->label('Schuljahr')->sortable(),
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('group_type')->label('Typ')->badge(),
                TextColumn::make('grade_level')->label('Stufe'),
                TextColumn::make('students_count')->label('SuS')->counts('students'),
            ])
            ->filters([
                SelectFilter::make('school_year_id')->label('Schuljahr')
                    ->options(SchoolYear::query()->orderByDesc('start_date')->pluck('label', 'id')),
                SelectFilter::make('group_type')->label('Typ')->options(['klasse' => 'Klasse', 'kurs' => 'Kurs']),
            ])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLearningGroups::route('/'),
            'create' => Pages\CreateLearningGroup::route('/create'),
            'edit' => Pages\EditLearningGroup::route('/{record}/edit'),
        ];
    }
}
