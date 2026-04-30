<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Permission\ScopeFilter;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\PrintJobRunner;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\Student\Models\Student;
use App\Filament\Concerns\AuthorizedPage;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Persönlicher LQ-Verlauf eines Schülers (Längsschnitt).
 *
 * Auswahl: Schüler (durchsuchbar, scope-gefiltert).
 * Anzeige: Chart.js-Liniendiagramm mit allen abgegebenen Versuchen.
 * Aktion:  PDF-Export via Druckvorlage 'verlaufsdiagramm'.
 */
class StudentHistoryChart extends Page implements HasForms
{
    use AuthorizedPage;
    use InteractsWithForms;

    protected static function requiredPermission(): ?string
    {
        return 'analytics.student_history';
    }

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Auswertung';
    protected static ?int $navigationSort = 10;
    protected static ?string $title = 'Verlaufsdiagramm';
    protected static ?string $navigationLabel = 'Verlauf (Schüler)';
    protected static string $view = 'filament.pages.student-history-chart';

    public ?array $data = [];
    public ?int $studentId = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('student_id')
                ->label('Schüler/in')
                ->required()
                ->searchable()
                ->live()
                ->options(function () {
                    $q = Student::query()->where('status', 'aktiv');
                    $q = app(ScopeFilter::class)->applyToStudents($q, auth()->user());

                    return $q->limit(500)->get()
                        ->mapWithKeys(fn (Student $s) => [
                            $s->id => $s->student_code.' – '.$s->first_name_encrypted.' '.$s->last_name_encrypted,
                        ])
                        ->all();
                })
                ->afterStateUpdated(fn ($state) => $this->studentId = $state ? (int) $state : null),
        ])->statePath('data');
    }

    /**
     * Liefert das Chart-Datenobjekt für die Blade-View.
     *
     * @return array{labels:list<string>, lq:list<int|null>, raw:list<int>, meta:list<array>}|null
     */
    public function getChartData(): ?array
    {
        if (! $this->studentId) {
            return null;
        }
        $student = Student::query()->find($this->studentId);
        if (! $student) {
            return null;
        }
        $history = app(AnalyticsService::class)->studentHistory($student);
        if ($history->isEmpty()) {
            return [
                'labels' => [],
                'lq' => [],
                'raw' => [],
                'meta' => [],
                'student' => $student,
            ];
        }

        return [
            'labels' => $history->map(fn ($r) => optional($r['submitted_at'])->format('d.m.Y') ?? '?')->all(),
            'lq' => $history->map(fn ($r) => $r['lq_current'])->all(),
            'raw' => $history->map(fn ($r) => $r['score_raw'])->all(),
            'meta' => $history->map(fn ($r) => [
                'test_run' => $r['test_run_name'],
                'assessment_type' => $r['assessment_type'],
                'school_year' => $r['school_year'],
                'parallel_form' => $r['parallel_form'],
            ])->all(),
            'student' => $student,
        ];
    }

    public function exportAction(): Action
    {
        return Action::make('export')
            ->label('Als PDF exportieren')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn () => $this->studentId !== null)
            ->action(function () {
                $student = Student::find($this->studentId);
                if (! $student) {
                    return null;
                }
                $history = app(AnalyticsService::class)->studentHistory($student);

                $template = PrintTemplate::query()->where('key', 'verlaufsdiagramm')->first();
                if (! $template?->currentVersion) {
                    Notification::make()->danger()
                        ->title('Druckvorlage "verlaufsdiagramm" fehlt')->send();

                    return null;
                }
                $version = $template->currentVersion;

                $vars = [
                    'school_name' => \App\Models\AppSetting::singleton()->school_name ?? '',
                    'student_name' => $student->first_name_encrypted.' '.$student->last_name_encrypted,
                    'student_code' => $student->student_code,
                    'date' => now()->format('d.m.Y'),
                    'history' => $history->map(fn ($r) => [
                        'date' => optional($r['submitted_at'])->format('d.m.Y'),
                        'label' => $r['test_run_name'],
                        'lq' => $r['lq_current'],
                    ])->all(),
                ];

                try {
                    $runner = new PrintJobRunner(app(GotenbergClient::class));
                    $html = $runner->renderTemplate($version->html_content, $vars);
                    $pdf = app(GotenbergClient::class)->htmlToPdf($html, $version->css_content);

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        'verlauf_'.$student->student_code.'.pdf',
                        ['Content-Type' => 'application/pdf'],
                    );
                } catch (\Throwable $e) {
                    Notification::make()->danger()
                        ->title('PDF-Export fehlgeschlagen')
                        ->body($e->getMessage())->send();

                    return null;
                }
            });
    }
}
