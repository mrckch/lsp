<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentResource\Pages;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Privacy\PrivacyService;
use App\Domain\Student\Models\Student;
use App\Filament\Resources\StudentResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detailansicht eines Schülers mit Stammdaten + Mini-Verlauf + Quick-Actions.
 */
class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;
    protected static string $view = 'filament.resources.student.view';

    public function getTitle(): string
    {
        return $this->record->student_code;
    }

    /** @return list<array<string,mixed>> */
    public function getRecentAttempts(int $limit = 5): array
    {
        return TestAttempt::query()
            ->with(['testRun.assessmentType', 'testRun.schoolYear'])
            ->where('student_id', $this->record->id)
            ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
            ->orderByDesc('submitted_at')
            ->limit($limit)
            ->get()
            ->map(fn (TestAttempt $a) => [
                'id' => $a->id,
                'date' => $a->submitted_at?->format('d.m.Y'),
                'test_run' => $a->testRun?->name,
                'assessment_type' => $a->testRun?->assessmentType?->label,
                'school_year' => $a->testRun?->schoolYear?->label,
                'parallel_form' => $a->parallel_form,
                'score_raw' => $a->score_raw,
                'lq' => $a->lq_current,
            ])
            ->all();
    }

    /** @return array{labels:list<string>, lq:list<int|null>}|null */
    public function getMiniChart(): ?array
    {
        $history = app(AnalyticsService::class)->studentHistory($this->record);
        if ($history->isEmpty()) {
            return null;
        }

        return [
            'labels' => $history->map(fn ($r) => optional($r['submitted_at'])->format('m/y') ?? '?')->all(),
            'lq' => $history->map(fn ($r) => $r['lq_current'])->all(),
        ];
    }

    public function getEnrollmentTimeline(): array
    {
        return $this->record->enrollments()
            ->with('schoolYear')
            ->orderByDesc('enrolled_at')
            ->get()
            ->map(fn ($e) => [
                'school_year' => $e->schoolYear?->label,
                'grade' => $e->grade_level,
                'is_repeater' => $e->is_repeater,
                'from' => $e->enrolled_at?->format('d.m.Y'),
                'to' => $e->ended_at?->format('d.m.Y'),
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $resolver = app(PermissionResolver::class);

        return [
            EditAction::make()
                ->visible(fn () => $resolver->can($user, 'students.manage')),
            Action::make('openHistory')
                ->label('Voll-Verlaufsdiagramm öffnen')
                ->icon('heroicon-o-chart-bar')
                ->color('info')
                ->visible(fn () => $resolver->can($user, 'analytics.student_history'))
                ->url(fn () => route('filament.admin.pages.student-history-chart').'?student='.$this->record->id),
            Action::make('archive')
                ->label('Archivieren')
                ->icon('heroicon-o-archive-box')
                ->color('warning')
                ->visible(fn () => $this->record->status === 'aktiv'
                    && $resolver->can($user, 'students.archive'))
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->archive('Manuelle Archivierung');
                    Notification::make()->success()->title('Archiviert')->send();
                }),
            Action::make('unarchive')
                ->label('Reaktivieren')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success')
                ->visible(fn () => $this->record->status === 'archiviert'
                    && $resolver->can($user, 'students.unarchive'))
                ->action(function () {
                    $this->record->unarchive();
                    Notification::make()->success()->title('Reaktiviert')->send();
                }),
            Action::make('exportPrivacyJson')
                ->label('DSGVO-Auskunft (JSON)')
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->visible(fn () => $resolver->can($user, 'students.view'))
                ->action(function () {
                    $data = app(PrivacyService::class)->exportStudentData($this->record);
                    $json = json_encode(
                        $data,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                    );

                    return response()->streamDownload(
                        fn () => print ($json),
                        'auskunft_'.$this->record->student_code.'.json',
                        ['Content-Type' => 'application/json'],
                    );
                }),
            Action::make('deleteStudent')
                ->label('Endgültig löschen')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => $resolver->can($user, 'students.delete'))
                ->form([
                    TextInput::make('reason')
                        ->label('Begründung (für Audit-Log)')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('z. B. DSGVO-Auskunfts- und Löschanfrage Eltern vom 15.04.2026'),
                    TextInput::make('confirm_code')
                        ->label('Zur Bestätigung den Schülercode '.$this->record->student_code.' eintippen')
                        ->required()
                        ->in([$this->record->student_code])
                        ->validationMessages(['in' => 'Code stimmt nicht überein.']),
                    Checkbox::make('confirm_irreversible')
                        ->label('Mir ist bewusst: diese Löschung ist endgültig und nicht rückgängig zu machen.')
                        ->required()
                        ->accepted(),
                ])
                ->before(function () {
                    // 2FA-Re-Auth-Check (students.delete ist mit requires_two_factor markiert)
                    $u = auth()->user();
                    if ($u === null || ! $u->two_factor_enabled) {
                        Notification::make()->danger()
                            ->title('2FA erforderlich')
                            ->body('Aktivieren Sie 2FA, bevor Sie Schülerdaten endgültig löschen.')
                            ->persistent()->send();
                        $this->halt();
                    }
                    $ttl = (int) config('lsp.two_factor.reauth_ttl_minutes', 15);
                    if ($u->last_2fa_at === null || $u->last_2fa_at->lt(now()->subMinutes($ttl))) {
                        Notification::make()->warning()
                            ->title('2FA-Bestätigung zu alt')
                            ->body("Bitte 2FA innerhalb der letzten $ttl Minuten erneut bestätigen.")
                            ->persistent()->send();
                        $this->halt();
                    }
                })
                ->action(function (array $data) {
                    $studentCode = $this->record->student_code;
                    $deleted = app(PrivacyService::class)->deleteStudent(
                        student: $this->record,
                        byUser: auth()->user(),
                        reason: $data['reason'],
                        confirmed: true,
                    );

                    if (! $deleted) {
                        Notification::make()->danger()->title('Löschung abgelehnt')->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title("Schüler $studentCode endgültig gelöscht")
                        ->body('Im Audit-Log dokumentiert. Daten sind nicht wiederherstellbar.')
                        ->send();

                    $this->redirect(StudentResource::getUrl('index'));
                }),
        ];
    }
}
