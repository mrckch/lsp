<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Attempt\TestEngine;
use App\Domain\FeedbackSet\Models\FeedbackSet;
use App\Domain\NormTable\Models\NormTable;
use App\Domain\NoticeText\Models\NoticeText;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\TestRun\Models\AssessmentType;
use App\Domain\TestRun\Models\TestRun;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\TestRunResource\Pages;
use Filament\Forms\Components\DatePicker;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TestRunResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string { return 'test_runs.view'; }
    protected static function createPermission(): ?string { return 'test_runs.create'; }
    protected static function editPermission(): ?string { return 'test_runs.manage_own'; }
    protected static function deletePermission(): ?string { return 'test_runs.delete'; }

    protected static ?string $model = TestRun::class;
    protected static ?string $navigationIcon = 'heroicon-o-play-circle';
    protected static ?string $navigationGroup = 'Erhebungen';
    protected static ?int $navigationSort = 10;
    protected static ?string $modelLabel = 'Testdurchlauf';
    protected static ?string $pluralModelLabel = 'Testdurchläufe';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Stammdaten')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(150)->columnSpanFull(),
                Select::make('school_year_id')->label('Schuljahr')
                    ->options(SchoolYear::orderByDesc('start_date')->pluck('label', 'id'))
                    ->required()->searchable(),
                Select::make('assessment_type_id')->label('Erhebungstyp')
                    ->options(AssessmentType::where('is_active', true)->orderBy('sort_order')->pluck('label', 'id'))
                    ->required(),
                Select::make('status')->required()->default('in_vorbereitung')
                    ->options([
                        'in_vorbereitung' => 'In Vorbereitung',
                        'aktiv' => 'Aktiv',
                        'pausiert' => 'Pausiert',
                        'abgeschlossen' => 'Abgeschlossen',
                        'archiviert' => 'Archiviert',
                    ]),
                DatePicker::make('scheduled_for')->label('Geplanter Termin'),
            ]),

            Section::make('Konfiguration')->columns(2)->schema([
                Select::make('questionnaire_id')->label('Fragebogen')
                    ->options(Questionnaire::where('status', 'aktiv')->pluck('name', 'id'))
                    ->searchable()->required(),
                Select::make('norm_table_id')->label('Normtabelle')
                    ->options(NormTable::where('is_active', true)->pluck('name', 'id'))
                    ->searchable(),
                Select::make('feedback_set_id')->label('Rückmeldeset')
                    ->options(FeedbackSet::where('status', 'aktiv')->pluck('name', 'id')),
                Select::make('notice_text_id')->label('Hinweistext')
                    ->options(NoticeText::where('status', 'aktiv')->pluck('name', 'id')),
                TextInput::make('time_limit_seconds')->label('Zeitlimit (Sek)')
                    ->numeric()->required()->default(180),
                TextInput::make('practice_time_seconds')->label('Übungszeit (Sek)')
                    ->numeric()->required()->default(30),
                Toggle::make('show_score_to_student')->label('Schüler sehen Ergebnis')->default(true),
                Toggle::make('allow_teacher_reset')->label('Lehrkraft darf Reset')->default(true),
            ]),

            Section::make('Lerngruppen')->schema([
                Select::make('learningGroups')->label('Teilnehmende Lerngruppen')
                    ->multiple()->relationship('learningGroups', 'name')
                    ->options(fn (callable $get) => LearningGroup::query()
                        ->when($get('school_year_id'), fn ($q, $sy) => $q->where('school_year_id', $sy))
                        ->pluck('name', 'id'))
                    ->preload()->searchable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('short_code')->label('Code')->badge(),
                TextColumn::make('schoolYear.label')->label('Schuljahr'),
                TextColumn::make('assessmentType.label')->label('Typ'),
                TextColumn::make('learning_groups_count')->label('Gruppen')->counts('learningGroups'),
                TextColumn::make('attempts_count')->label('Versuche')->counts('attempts'),
                BadgeColumn::make('status')->colors([
                    'gray' => 'in_vorbereitung',
                    'success' => 'aktiv',
                    'warning' => 'pausiert',
                    'primary' => 'abgeschlossen',
                    'gray' => 'archiviert',
                ]),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'in_vorbereitung' => 'In Vorbereitung',
                    'aktiv' => 'Aktiv',
                    'pausiert' => 'Pausiert',
                    'abgeschlossen' => 'Abgeschlossen',
                    'archiviert' => 'Archiviert',
                ]),
                SelectFilter::make('school_year_id')->label('Schuljahr')->relationship('schoolYear', 'label'),
            ])
            ->actions([
                EditAction::make(),
                Action::make('issueCodes')
                    ->label('Login-Codes erzeugen')
                    ->icon('heroicon-o-key')
                    ->requiresConfirmation()
                    ->action(function (TestRun $record) {
                        $count = app(TestEngine::class)->issueLoginCodes($record);
                        Notification::make()->success()
                            ->title("$count neue Login-Codes erzeugt")->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTestRuns::route('/'),
            'create' => Pages\CreateTestRun::route('/create'),
            'edit' => Pages\EditTestRun::route('/{record}/edit'),
        ];
    }
}
