<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Analytics\SupportListCsvExporter;
use App\Domain\Permission\ScopeFilter;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\PrintJobRunner;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\SupportThreshold\ThresholdEvaluator;
use App\Filament\Concerns\AuthorizedPage;
use App\Filament\Concerns\HandlesPrintErrors;
use App\Jobs\MailSupportListJob;
use App\Models\AppSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Listet alle Schüler, die mind. eine konfigurierte Förderbedarfs-Schwelle erreichen.
 * Filter: Schuljahr, Severity, Klassenstufe.
 */
class SupportListPage extends Page implements HasForms
{
    use AuthorizedPage;
    use HandlesPrintErrors;
    use InteractsWithForms;

    protected static function requiredPermission(): ?string
    {
        return 'analytics.support_list';
    }

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationGroup = 'Auswertung';
    protected static ?int $navigationSort = 20;
    protected static ?string $title = 'Förderbedarfs-Liste';
    protected static ?string $navigationLabel = 'Förderbedarf';
    protected static string $view = 'filament.pages.support-list';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['severity' => 'all']);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('school_year_id')->label('Schuljahr')
                ->options(SchoolYear::query()->orderByDesc('start_date')->pluck('label', 'id'))
                ->placeholder('Alle Schuljahre')
                ->live(),
            Select::make('severity')->label('Mindest-Schweregrad')
                ->options([
                    'all' => 'Alle Treffer',
                    'auffaellig' => 'auffällig + Förderbedarf',
                    'foerderbedarf' => 'nur Förderbedarf',
                ])
                ->default('all')
                ->required()
                ->live(),
            Select::make('grade_level')->label('Klassenstufe')
                ->options(['5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', 'EF' => 'EF', 'Q1' => 'Q1', 'Q2' => 'Q2'])
                ->placeholder('Alle Stufen')
                ->live(),
        ])->statePath('data')->columns(3);
    }

    /** @return list<array<string,mixed>> */
    public function getRows(): array
    {
        $data = $this->form->getState();
        $schoolYearId = $data['school_year_id'] ?? null;
        $severityFilter = $data['severity'] ?? 'all';
        $grade = $data['grade_level'] ?? null;

        $hits = app(ThresholdEvaluator::class)->evaluateAll($schoolYearId ? (int) $schoolYearId : null);

        $scopeFilter = app(ScopeFilter::class);
        $allowedIds = $scopeFilter->scopesFor(auth()->user());

        $rows = [];
        foreach ($hits as $hit) {
            $student = $hit['student'];
            $threshold = $hit['threshold'];
            $attempt = $hit['attempt'];

            // Severity-Filter
            if ($severityFilter === 'foerderbedarf' && $threshold->severity !== 'foerderbedarf') {
                continue;
            }
            if ($severityFilter === 'auffaellig' && ! in_array($threshold->severity, ['auffaellig', 'foerderbedarf'], true)) {
                continue;
            }

            // Lerngruppe ermitteln
            $membership = $student->memberships()->with('learningGroup')->orderByDesc('id')->first();
            $group = $membership?->learningGroup;

            // Scope: wenn User scope hat und Gruppe nicht im Scope → skip
            if ($allowedIds !== null && $group && ! in_array($group->id, $allowedIds, true)) {
                continue;
            }

            // Klassenstufen-Filter
            if ($grade !== null && $grade !== '' && $group && $group->grade_level !== $grade) {
                continue;
            }

            $rows[] = [
                'student_id' => $student->id,
                'student_code' => $student->student_code,
                'student_name' => $student->first_name_encrypted.' '.$student->last_name_encrypted,
                'group' => $group?->name ?? '–',
                'grade_level' => $group?->grade_level ?? '–',
                'lq' => $attempt->lq_current,
                'date' => $attempt->submitted_at?->format('d.m.Y'),
                'severity' => $threshold->severity,
                'threshold_name' => $threshold->name,
            ];
        }

        // Sort: schwerste zuerst, dann LQ aufsteigend
        usort($rows, function ($a, $b) {
            $rank = ['foerderbedarf' => 3, 'auffaellig' => 2, 'hinweis' => 1];
            $cmp = ($rank[$b['severity']] ?? 0) <=> ($rank[$a['severity']] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($a['lq'] ?? 999) <=> ($b['lq'] ?? 999);
        });

        return $rows;
    }

    public function exportPdfAction(): Action
    {
        return Action::make('exportPdf')
            ->label('Liste als PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('info')
            ->visible(fn () => auth()->user()?->hasPermission('print.generate_with_clearname') ?? false)
            ->action(function () {
                $rows = $this->getRows();
                if (empty($rows)) {
                    Notification::make()->warning()->title('Keine Treffer für Export')->send();

                    return null;
                }

                return self::runPrintAction(function () use ($rows) {
                    $template = PrintTemplate::query()->where('key', 'foerderbedarfsliste')->first();
                    if (! $template?->currentVersion) {
                        Notification::make()->danger()
                            ->title('Druckvorlage "foerderbedarfsliste" fehlt')->send();

                        return null;
                    }
                    $version = $template->currentVersion;
                    $vars = [
                        'school_name' => AppSetting::singleton()->school_name ?? '',
                        'date' => now()->format('d.m.Y'),
                        'rows' => $rows,
                    ];

                    $runner = new PrintJobRunner(app(GotenbergClient::class));
                    $html = $runner->renderTemplate($version->html_content, $vars);
                    $pdf = app(GotenbergClient::class)->htmlToPdf($html, $version->css_content);

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        'foerderbedarf_'.now()->format('Ymd_His').'.pdf',
                        ['Content-Type' => 'application/pdf'],
                    );
                }, 'PDF-Export');
            });
    }

    public function exportCsvAction(): Action
    {
        return Action::make('exportCsv')
            ->label('Liste als CSV')
            ->icon('heroicon-o-table-cells')
            ->color('gray')
            ->visible(fn () => auth()->user()?->hasPermission('analytics.support_list') ?? false)
            ->action(function () {
                $rows = $this->getRows();
                if (empty($rows)) {
                    Notification::make()->warning()->title('Keine Treffer für Export')->send();

                    return null;
                }

                $csv = app(SupportListCsvExporter::class)->toCsv($rows);

                return response()->streamDownload(
                    fn () => print ($csv),
                    'foerderbedarf_'.now()->format('Ymd_His').'.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }

    public function mailListAction(): Action
    {
        return Action::make('mailList')
            ->label('Liste per Mail')
            ->icon('heroicon-o-envelope')
            ->color('info')
            ->visible(fn () => auth()->user()?->hasPermission('mail.send_with_clearname') ?? false)
            ->form([
                TextInput::make('recipient')->label('Empfänger-E-Mail')
                    ->email()->required()
                    ->helperText('Z. B. Förderkoordination oder Schulleitung. Liste enthält Klarnamen.'),
                TextInput::make('subject')->label('Betreff')->required()
                    ->default('Förderbedarfs-Liste – Lese-Screening'),
                Textarea::make('body')->label('Nachricht')->rows(4)
                    ->default('Anbei die aktuelle Förderbedarfs-Liste mit den geltenden Filtern.'),
            ])
            ->before(function () {
                $u = auth()->user();
                if ($u === null || ! $u->two_factor_enabled) {
                    Notification::make()->danger()
                        ->title('2FA erforderlich')
                        ->body('Aktivieren Sie 2FA, bevor Sie Listen mit Klarnamen versenden.')
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
                $rows = $this->getRows();
                if (empty($rows)) {
                    Notification::make()->warning()->title('Keine Treffer für Versand')->send();

                    return;
                }

                MailSupportListJob::dispatch(
                    filters: $this->form->getState(),
                    recipient: $data['recipient'],
                    subject: $data['subject'],
                    bodyHtml: nl2br(e($data['body'])),
                    userId: auth()->id(),
                );

                Notification::make()->success()
                    ->title('Listen-Mail-Job gestartet')
                    ->body('Versand läuft im Hintergrund. Status im Mailprotokoll.')
                    ->send();
            });
    }
}
