<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Questionnaire\Models\Questionnaire;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\QuestionnaireResource\Pages;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuestionnaireResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string
    {
        return 'questionnaires.view';
    }

    protected static function createPermission(): ?string
    {
        return 'questionnaires.manage';
    }

    protected static function editPermission(): ?string
    {
        return 'questionnaires.manage';
    }

    protected static function deletePermission(): ?string
    {
        return 'questionnaires.manage';
    }

    protected static ?string $model = Questionnaire::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Test-Konfiguration';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Fragebogen';

    protected static ?string $pluralModelLabel = 'Fragebögen';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Stammdaten')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(150)->columnSpanFull(),
                TextInput::make('parallel_form')->label('Parallelform')->maxLength(10)
                    ->placeholder('z. B. A1, A2, B1, B2'),
                TextInput::make('grade_level_target')->label('Schulstufe (Ziel)')->maxLength(20)
                    ->placeholder('5-6, 7-9, ...'),
                TextInput::make('default_time_limit_seconds')->label('Standard-Zeitlimit (Sek)')
                    ->numeric()->default(180)->required(),
                TextInput::make('practice_time_seconds')->label('Übungs-Zeit (Sek)')
                    ->numeric()->default(30)->required(),
                Select::make('status')->required()->default('entwurf')
                    ->options(['entwurf' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert']),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),

            Section::make('Übungsfragen')
                ->description('Werden vor dem eigentlichen Test mit kürzerem Zeitlimit gezeigt.')
                ->collapsed()
                ->schema([
                    Repeater::make('practiceQuestions')->relationship()
                        ->label('Übungsfragen')->orderColumn('sort_order')
                        ->defaultItems(0)
                        ->schema([
                            TextInput::make('question_text')->label('Satz')->required()->columnSpan(3),
                            Select::make('correct_answer')->label('Richtige Antwort')
                                ->options(['richtig' => 'richtig', 'falsch' => 'falsch'])
                                ->required(),
                        ])->columns(4),
                ]),

            Section::make('Test-Fragen')->schema([
                Repeater::make('questions')->relationship()
                    ->label('Fragen')->orderColumn('sort_order')
                    ->defaultItems(0)
                    ->cloneable()
                    ->schema([
                        TextInput::make('question_text')->label('Satz')->required()->columnSpan(3),
                        Select::make('correct_answer')->label('Richtige Antwort')
                            ->options(['richtig' => 'richtig', 'falsch' => 'falsch'])
                            ->required(),
                    ])->columns(4),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('parallel_form')->label('Form')->badge(),
                TextColumn::make('grade_level_target')->label('Stufe'),
                TextColumn::make('questions_count')->label('Fragen')->counts('questions'),
                TextColumn::make('default_time_limit_seconds')->label('Zeit (s)'),
                BadgeColumn::make('status')
                    ->colors(['warning' => 'entwurf', 'success' => 'aktiv', 'gray' => 'archiviert']),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'entwurf' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert',
                ]),
            ])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestionnaires::route('/'),
            'create' => Pages\CreateQuestionnaire::route('/create'),
            'edit' => Pages\EditQuestionnaire::route('/{record}/edit'),
        ];
    }
}
