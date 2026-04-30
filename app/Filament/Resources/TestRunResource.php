<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Attempt\TestEngine;
use App\Domain\FeedbackSet\Models\FeedbackSet;
use App\Domain\Mail\MailService;
use App\Domain\NormTable\Models\NormTable;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Permission\ScopeFilter;
use App\Domain\NoticeText\Models\NoticeText;
use App\Domain\PrintJob\BulkFeedbackGenerator;
use App\Jobs\GenerateBulkFeedbackZipJob;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\TestRun\Models\AssessmentType;
use App\Domain\TestRun\Models\TestRun;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Concerns\HandlesPrintErrors;
use App\Filament\Resources\TestRunResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
    use HandlesPrintErrors;

    protected static function viewPermission(): ?string { return 'test_runs.view'; }
    protected static function createPermission(): ?string { return 'test_runs.create'; }
    protected static function editPermission(): ?string { return 'test_runs.manage_own'; }
    protected static function deletePermission(): ?string { return 'test_runs.delete'; }

    /**
     * Per-Record-Authorisierung:
     *  - eigener Run (owner_user_id === user.id) → 'test_runs.manage_own'
     *  - fremder Run                              → 'test_runs.manage_all'
     *  - immer zusätzlich: Scope-Check auf die verknüpften Lerngruppen
     */
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return self::authorizeForOwnership($record, ownPermission: 'test_runs.manage_own', allPermission: 'test_runs.manage_all');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return self::authorizeForOwnership($record, ownPermission: 'test_runs.delete', allPermission: 'test_runs.delete')
            && self::canEdit($record);
    }

    private static function authorizeForOwnership(
        \Illuminate\Database\Eloquent\Model $record,
        string $ownPermission,
        string $allPermission,
    ): bool {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        $resolver = app(PermissionResolver::class);
        $isOwner = isset($record->owner_user_id) && (int) $record->owner_user_id === (int) $user->id;
        $key = $isOwner ? $ownPermission : $allPermission;

        if (! $resolver->can($user, $key)) {
            return false;
        }

        // Scope: Run muss mit mindestens einer scope-Lerngruppe verknüpft sein
        $scopes = app(ScopeFilter::class)->scopesFor($user);
        if ($scopes === null) {
            return true;
        }
        if (empty($scopes)) {
            return false;
        }

        return $record->learningGroups()
            ->whereIn('learning_groups.id', $scopes)
            ->exists();
    }

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
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $q) =>
                app(ScopeFilter::class)->applyToTestRuns($q, auth()->user()))
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
                Action::make('bulkPdf')
                    ->label('Rückmeldungen-ZIP erzeugen (Hintergrund)')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->visible(fn () => auth()->user()?->hasPermission('print.generate_with_clearname') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Der Job läuft im Hintergrund. Sobald das ZIP fertig ist, erscheint es unter '.
                        '"Drucksachen > Erzeugte Dokumente" zum Download.')
                    ->action(function (TestRun $record) {
                        GenerateBulkFeedbackZipJob::dispatch($record->id, auth()->id());
                        Notification::make()->success()
                            ->title('Bulk-PDF-Job gestartet')
                            ->body('Das fertige ZIP findest du unter "Drucksachen > Erzeugte Dokumente".')
                            ->send();
                    }),
                Action::make('bulkMail')
                    ->label('Rückmeldungen per Mail')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->visible(fn () => auth()->user()?->hasPermission('mail.send_with_clearname') ?? false)
                    ->form([
                        TextInput::make('recipient')->label('Empfänger-E-Mail')
                            ->email()->required()
                            ->helperText('Z. B. die Klassenlehrkraft. Alle Rückmeldungs-PDFs werden als ZIP angehängt.'),
                        TextInput::make('subject')->label('Betreff')->required()
                            ->default(fn (TestRun $record) => 'Rückmeldungen Lese-Screening: '.$record->name),
                        Textarea::make('body')->label('Nachricht')->rows(4)
                            ->default('Anbei die Rückmeldungs-PDFs der Lese-Screening-Erhebung.'),
                    ])
                    ->before(function () {
                        // 2FA-Re-Auth-Schwelle für Klarnamen-Versand
                        $user = auth()->user();
                        if ($user === null || ! $user->two_factor_enabled) {
                            Notification::make()->danger()
                                ->title('2FA erforderlich')
                                ->body('Aktivieren Sie 2FA in Ihrem Konto, bevor Sie Rückmeldungen mit Klarnamen versenden.')
                                ->persistent()->send();
                            $this->halt();
                        }
                        $ttl = (int) config('lsp.two_factor.reauth_ttl_minutes', 15);
                        if ($user->last_2fa_at === null || $user->last_2fa_at->lt(now()->subMinutes($ttl))) {
                            Notification::make()->warning()
                                ->title('2FA-Bestätigung zu alt')
                                ->body("Bitte 2FA innerhalb der letzten $ttl Minuten erneut bestätigen.")
                                ->persistent()->send();
                            $this->halt();
                        }
                    })
                    ->action(function (TestRun $record, array $data) {
                        self::runPrintAction(function () use ($record, $data) {
                            $result = app(BulkFeedbackGenerator::class)
                                ->generateForRun($record, forUser: auth()->user());
                            if ($result['count'] === 0) {
                                Notification::make()->warning()
                                    ->title('Keine abgegebenen Versuche im Scope')->send();

                                return null;
                            }

                            $msg = app(MailService::class)->sendWithRawAttachment(
                                to: [$data['recipient']],
                                subject: $data['subject'],
                                bodyHtml: nl2br(e($data['body'])),
                                attachmentName: 'rueckmeldungen_'.$record->short_code.'.zip',
                                attachmentMime: 'application/zip',
                                attachmentBytes: file_get_contents($result['zip']),
                                includesClearnames: true,
                                userId: auth()->id(),
                            );
                            @unlink($result['zip']);

                            if ($msg->status === 'sent') {
                                Notification::make()->success()
                                    ->title("Mail mit {$result['count']} PDFs versendet")
                                    ->body($data['recipient'])->send();
                            } else {
                                Notification::make()->danger()
                                    ->title('Mailversand fehlgeschlagen')
                                    ->body($msg->error_message ?? '')->send();
                            }

                            return null;
                        }, 'Bulk-Mail');
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
