<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestRunResource\Pages;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Attempt\TestEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\ScopeFilter;
use App\Domain\PrintJob\LoginCardSheetGenerator;
use App\Domain\TestRun\Models\TestRun;
use App\Filament\Resources\TestRunResource;
use App\Filament\Resources\TestRunResource\Widgets\TestRunMonitorStats;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Live-Übersicht eines Testdurchlaufs für Admin und durchführende Lehrkraft:
 * je Schüler Login-Code, Status, Fortschritt, Restzeit, Ergebnis – plus
 * Eingriffe (Versuch zurücksetzen/beenden, Code sperren, Code/QR anzeigen, Karte nachdrucken).
 *
 * Sichtbar mit test_runs.view für Runs im eigenen Scope; die Tabelle zeigt nur
 * Schüler aus den eigenen Lerngruppen.
 */
class MonitorTestRun extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = TestRunResource::class;

    protected static string $view = 'filament.resources.test-run.monitor';

    private const CODE_STATUS = [
        'aktiv' => 'nicht angemeldet',
        'in_bearbeitung' => 'angemeldet',
        'verbraucht' => 'fertig',
        'gesperrt' => 'gesperrt',
    ];

    private const ATTEMPT_STATUS = [
        'gestartet' => 'läuft',
        'abgegeben' => 'abgegeben',
        'zeit_abgelaufen' => 'Zeit abgelaufen',
        'abgebrochen' => 'abgebrochen',
        'zurueckgesetzt' => 'zurückgesetzt',
    ];

    /** Anzahl Fragen des Fragebogens (memoisiert pro Request) */
    protected ?int $questionCount = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canViewAny(), 403);
        $visible = app(ScopeFilter::class)
            ->applyToTestRuns(TestRun::query()->whereKey($this->record->getKey()), auth()->user())
            ->exists();
        abort_unless($visible, 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Übersicht – '.$this->record->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $run = $this->getRun();
        $parts = [
            'Status: '.$run->status,
            'Zeit: '.$run->time_limit_seconds.' s',
            'Übung: '.$run->practice_time_seconds.' s',
        ];
        $text = e(implode(' · ', $parts)).' · aktualisiert sich alle 10 Sekunden';
        if (! app(CryptoService::class)->isUnlocked()) {
            $text .= '<br><span style="color:#b45309;">Namen werden als *** angezeigt – für Klarnamen unter „Klarnamen → Entsperren“ freischalten.</span>';
        }

        return new HtmlString($text);
    }

    public function getBreadcrumbs(): array
    {
        return [
            TestRunResource::getUrl('index') => 'Testdurchläufe',
            '#' => 'Übersicht',
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [TestRunMonitorStats::class];
    }

    public function getWidgetData(): array
    {
        return ['record' => $this->record];
    }

    protected function getHeaderActions(): array
    {
        $run = $this->getRun();

        return [
            PageAction::make('printCards')
                ->label('Login-Karten drucken')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasPermission('print.generate_with_clearname') ?? false)
                ->action(fn () => TestRunResource::downloadLoginCards($run)),
            PageAction::make('edit')
                ->label('Bearbeiten')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn () => TestRunResource::canEdit($run))
                ->url(TestRunResource::getUrl('edit', ['record' => $run])),
        ];
    }

    public function table(Table $table): Table
    {
        $run = $this->getRun();
        $runGroupIds = $run->learningGroups->pluck('id')->all();
        $canSeeCodes = auth()->user()?->hasPermission('login_codes.view') ?? false;

        return $table
            ->query(function () use ($run) {
                $q = StudentLoginCode::query()
                    ->withAttemptInfo()
                    ->with(['student.learningGroups', 'latestAttempt' => fn ($a) => $a->withCount('answers')])
                    ->where('student_login_codes.test_run_id', $run->id);

                return app(ScopeFilter::class)->applyToLoginCodes($q, auth()->user());
            })
            ->poll('10s')
            ->defaultSort('student_login_codes.id')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Noch keine Login-Codes')
            ->emptyStateDescription('Login-Codes werden über „Aktionen → Login-Codes erzeugen“ in der Liste der Testdurchläufe angelegt.')
            ->columns([
                TextColumn::make('name')->label('Name')
                    ->getStateUsing(fn (StudentLoginCode $r) => trim(($r->student?->first_name_encrypted ?? '').' '.($r->student?->last_name_encrypted ?? '')))
                    ->description(fn (StudentLoginCode $r) => $r->student?->student_code),
                TextColumn::make('group')->label('Gruppe')
                    ->getStateUsing(fn (StudentLoginCode $r) => TestRunResource::studentGroupLabel($r->student, $runGroupIds)),
                TextColumn::make('login_code')->label('Login-Code')
                    ->fontFamily('mono')->copyable()->copyMessage('Code kopiert')
                    ->visible($canSeeCodes),
                TextColumn::make('status')->label('Code')->badge()
                    ->formatStateUsing(fn (string $state) => self::CODE_STATUS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'aktiv' => 'gray',
                        'in_bearbeitung' => 'info',
                        'verbraucht' => 'success',
                        'gesperrt' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('attempt_status')->label('Versuch')->badge()
                    ->getStateUsing(fn (StudentLoginCode $r) => $this->attemptPhase($r->latestAttempt))
                    ->color(fn (string $state) => match ($state) {
                        'läuft' => 'info',
                        'Hinweise/Übung' => 'warning',
                        'abgegeben' => 'success',
                        'Zeit abgelaufen' => 'warning',
                        'zurückgesetzt', 'abgebrochen' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('progress')->label('Beantwortet')
                    ->getStateUsing(fn (StudentLoginCode $r) => $r->latestAttempt
                        ? $r->latestAttempt->answers_count.' / '.$this->questionCount()
                        : '–'),
                TextColumn::make('remaining')->label('Restzeit')
                    ->getStateUsing(function (StudentLoginCode $r) {
                        $a = $r->latestAttempt;
                        if ($a === null || $a->status !== 'gestartet' || $a->main_started_at === null) {
                            return '–';
                        }
                        $s = $a->remainingSeconds();

                        return intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
                    }),
                TextColumn::make('latestAttempt.score_raw')->label('Rohwert')->toggleable()
                    ->getStateUsing(fn (StudentLoginCode $r) => $this->isFinished($r->latestAttempt) ? $r->latestAttempt->score_raw : null)
                    ->placeholder('–'),
                TextColumn::make('latestAttempt.lq_current')->label('LQ')
                    ->getStateUsing(fn (StudentLoginCode $r) => $this->isFinished($r->latestAttempt) ? $r->latestAttempt->lq_current : null)
                    ->placeholder('–')
                    ->color(fn ($state) => $state !== null && (int) $state < 85 ? 'warning' : null)
                    ->weight('bold'),
                TextColumn::make('times')->label('Start / Abgabe')->toggleable()
                    ->getStateUsing(function (StudentLoginCode $r) {
                        $a = $r->latestAttempt;
                        if ($a === null) {
                            return '–';
                        }
                        $start = ($a->main_started_at ?? $a->started_at)?->format('H:i');

                        return ($start ?? '–').' / '.($a->submitted_at?->format('H:i') ?? '–');
                    }),
                TextColumn::make('attempts_total')->label('Versuche')->alignCenter()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Code-Status')->options(self::CODE_STATUS)
                    ->query(fn (Builder $q, array $data) => filled($data['value'] ?? null)
                        ? $q->where('student_login_codes.status', $data['value'])
                        : $q),
            ])
            ->actions([
                ActionGroup::make([
                    $this->showCodeAction()->visible($canSeeCodes),
                    $this->printCardAction(),
                    $this->resetAttemptAction(),
                    $this->endAttemptAction(),
                    $this->toggleLockAction(),
                    $this->historyAction(),
                ])->label('Aktionen')->button()->size('sm'),
            ]);
    }

    // ── Aktionen ────────────────────────────────────────────────────────

    private function showCodeAction(): Action
    {
        return Action::make('showCode')
            ->label('Code / QR anzeigen')
            ->icon('heroicon-o-qr-code')
            ->modalHeading(fn (StudentLoginCode $r) => 'Login-Code '.trim(($r->student?->first_name_encrypted ?? '').' '.($r->student?->last_name_encrypted ?? '')))
            ->modalContent(function (StudentLoginCode $r) {
                $generator = app(LoginCardSheetGenerator::class);
                $url = LoginCardSheetGenerator::loginUrl((string) config('app.url'), $r->login_code);

                return view('filament.resources.test-run.show-code', [
                    'code' => $r->login_code,
                    'url' => $url,
                    'qr' => new HtmlString($generator->qrSvgForUrl($url)),
                    'status' => self::CODE_STATUS[$r->status] ?? $r->status,
                ]);
            })
            ->modalWidth('md')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Schließen');
    }

    private function printCardAction(): Action
    {
        return Action::make('printCard')
            ->label('Karte nachdrucken (PDF)')
            ->icon('heroicon-o-printer')
            ->visible(fn () => auth()->user()?->hasPermission('print.generate_with_clearname') ?? false)
            ->action(fn (StudentLoginCode $r) => TestRunResource::downloadLoginCards($this->getRun(), $r->id));
    }

    private function resetAttemptAction(): Action
    {
        return Action::make('resetAttempt')
            ->label('Versuch zurücksetzen')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (StudentLoginCode $r) => $this->canResetAttempts()
                && $this->attemptOf($r) !== null
                && $this->attemptOf($r)->status !== 'zurueckgesetzt')
            ->modalHeading('Versuch zurücksetzen')
            ->modalDescription('Alle Antworten dieses Versuchs werden gelöscht. Der Schüler kann sich danach mit '.
                'demselben Code neu anmelden und startet von vorn (mit voller Zeit). Der alte Versuch bleibt '.
                'als „zurückgesetzt“ im Verlauf sichtbar.')
            ->form([
                Textarea::make('reason')->label('Grund')->required()->maxLength(255)->rows(2)
                    ->placeholder('z. B. Tablet abgestürzt, falscher Schüler angemeldet …'),
            ])
            ->modalSubmitActionLabel('Zurücksetzen')
            ->action(function (StudentLoginCode $r, array $data) {
                $attempt = $this->attemptOf($r);
                abort_unless($attempt !== null && $this->canResetAttempts(), 403);
                $previousStatus = $attempt->status;

                app(TestEngine::class)->resetAttempt($attempt, (int) auth()->id(), $data['reason']);
                app(AuditLogger::class)->logUser(auth()->user(), 'attempts.reset', 'test_attempt', $attempt->id, [
                    'test_run_id' => $attempt->test_run_id,
                    'previous_status' => $previousStatus,
                    'reason' => $data['reason'],
                ]);

                Notification::make()->success()
                    ->title('Versuch zurückgesetzt')
                    ->body('Der Schüler kann sich mit seinem Code neu anmelden.')
                    ->send();
            });
    }

    private function endAttemptAction(): Action
    {
        return Action::make('endAttempt')
            ->label('Versuch beenden & werten')
            ->icon('heroicon-o-stop-circle')
            ->color('warning')
            ->visible(fn (StudentLoginCode $r) => $this->canResetAttempts()
                && $this->attemptOf($r)?->status === 'gestartet')
            ->requiresConfirmation()
            ->modalHeading('Versuch jetzt beenden?')
            ->modalDescription('Der Versuch wird mit den bisher gespeicherten Antworten abgegeben und gewertet.')
            ->action(function (StudentLoginCode $r) {
                $attempt = $this->attemptOf($r);
                abort_unless($attempt !== null && $this->canResetAttempts(), 403);

                app(TestEngine::class)->submitAttempt($attempt, 'lehrkraft');
                app(AuditLogger::class)->logUser(auth()->user(), 'attempts.end', 'test_attempt', $attempt->id, [
                    'test_run_id' => $attempt->test_run_id,
                ]);

                Notification::make()->success()->title('Versuch beendet und gewertet')->send();
            });
    }

    private function toggleLockAction(): Action
    {
        return Action::make('toggleLock')
            ->label(fn (StudentLoginCode $r) => $r->status === 'gesperrt' ? 'Code entsperren' : 'Code sperren')
            ->icon(fn (StudentLoginCode $r) => $r->status === 'gesperrt' ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
            ->color('gray')
            ->visible(fn (StudentLoginCode $r) => (auth()->user()?->hasPermission('login_codes.lock') ?? false)
                && $r->status !== 'verbraucht')
            ->requiresConfirmation()
            ->modalDescription(fn (StudentLoginCode $r) => $r->status === 'gesperrt'
                ? 'Mit dem Code kann man sich wieder anmelden.'
                : 'Mit dem Code kann sich niemand mehr anmelden. Eine bereits laufende Sitzung läuft weiter – '.
                  'ggf. zusätzlich „Versuch beenden“.')
            ->action(function (StudentLoginCode $r) {
                $unlock = $r->status === 'gesperrt';
                $newStatus = $unlock
                    ? ($this->attemptOf($r)?->status === 'gestartet' ? 'in_bearbeitung' : 'aktiv')
                    : 'gesperrt';
                $r->update(['status' => $newStatus]);
                app(AuditLogger::class)->logUser(
                    auth()->user(),
                    $unlock ? 'login_codes.unlock' : 'login_codes.lock',
                    'student_login_code',
                    $r->id,
                    ['test_run_id' => $r->test_run_id],
                );

                Notification::make()->success()->title($unlock ? 'Code entsperrt' : 'Code gesperrt')->send();
            });
    }

    private function historyAction(): Action
    {
        return Action::make('history')
            ->label('Versuchsverlauf')
            ->icon('heroicon-o-clock')
            ->visible(fn (StudentLoginCode $r) => $this->attemptOf($r) !== null)
            ->modalHeading('Versuchsverlauf')
            ->modalContent(fn (StudentLoginCode $r) => view('filament.resources.test-run.attempt-history', [
                'attempts' => TestAttempt::query()
                    ->with('resetBy')
                    ->withCount('answers')
                    ->where('student_id', $r->student_id)
                    ->where('test_run_id', $r->test_run_id)
                    ->orderByDesc('id')
                    ->get(),
                'statusLabels' => self::ATTEMPT_STATUS,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Schließen');
    }

    // ── Hilfen ──────────────────────────────────────────────────────────

    /**
     * Zurücksetzen/Beenden: attempts.reset; ohne test_runs.manage_all nur,
     * wenn der Run „Lehrkraft darf Reset“ erlaubt.
     */
    public function canResetAttempts(): bool
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasPermission('attempts.reset')) {
            return false;
        }

        return $user->hasPermission('test_runs.manage_all') || (bool) $this->getRun()->allow_teacher_reset;
    }

    /**
     * Jüngster Versuch zu einem Code. Nutzt die vorgeladene Relation der Tabelle;
     * bei Aktionen, deren Record ohne `latest_attempt_id` aufgelöst wurde, frisch aus der DB.
     */
    private function attemptOf(StudentLoginCode $r): ?TestAttempt
    {
        if (array_key_exists('latest_attempt_id', $r->getAttributes())) {
            return $r->latestAttempt;
        }

        return TestAttempt::query()
            ->where('student_id', $r->student_id)
            ->where('test_run_id', $r->test_run_id)
            ->latest('id')
            ->first();
    }

    private function attemptPhase(?TestAttempt $a): string
    {
        if ($a === null) {
            return 'nicht gestartet';
        }
        if ($a->status === 'gestartet' && $a->main_started_at === null) {
            return 'Hinweise/Übung';
        }

        return self::ATTEMPT_STATUS[$a->status] ?? $a->status;
    }

    private function isFinished(?TestAttempt $a): bool
    {
        return $a !== null && in_array($a->status, ['abgegeben', 'zeit_abgelaufen'], true);
    }

    private function questionCount(): int
    {
        return $this->questionCount ??= (int) ($this->getRun()->questionnaire?->questions()->count() ?? 0);
    }

    private function getRun(): TestRun
    {
        /** @var TestRun $run */
        $run = $this->record;

        return $run;
    }
}
