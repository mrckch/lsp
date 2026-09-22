<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Analytics\AnalysisCsvExporter;
use App\Domain\Analytics\AnalysisDataset;
use App\Domain\Analytics\AnalysisFilter;
use App\Domain\Analytics\AnalysisPdfRenderer;
use App\Domain\Analytics\AnalysisReport;
use App\Domain\Audit\AuditLogger;
use App\Domain\Crypto\CryptoService;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\Models\GeneratedDocument;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\TestRun\Models\AssessmentType;
use App\Domain\TestRun\Models\TestRun;
use App\Filament\Concerns\AuthorizedPage;
use App\Filament\Concerns\HandlesPrintErrors;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Auswertung → Datenanalyse: Erhebungsdaten filtern, Gruppen (Klassen, Geschlecht …)
 * als Boxplot + Kennzahlen vergleichen, als A4-PDF (mit Klarnamen) oder CSV exportieren.
 * Sichtbarkeit: AnalysisDataset (Schulleitung/Admin alles, Lehrkräfte eigene Lerngruppen).
 */
class DataAnalysisPage extends Page implements HasForms
{
    use AuthorizedPage;
    use HandlesPrintErrors;
    use InteractsWithForms;

    protected static function requiredPermission(): ?string
    {
        return 'analytics.cohort_overview';
    }

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationGroup = 'Auswertung';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Datenanalyse';

    protected static ?string $navigationLabel = 'Datenanalyse';

    protected static ?string $slug = 'datenanalyse';

    protected static string $view = 'filament.pages.data-analysis';

    /** Filterzustand; steht in der URL, damit Links teilbar sind */
    #[Url(as: 'f')]
    public ?array $filters = [];

    #[Url(as: 'bereiche')]
    public bool $showBands = false;

    #[Url(as: 'geschlecht')]
    public bool $byGender = false;

    public function mount(): void
    {
        $this->form->fill(array_merge(self::defaults(), array_filter(
            (array) $this->filters,
            fn ($v) => $v !== null && $v !== '' && $v !== [],
        )));
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $today = now()->toDateString();
        $year = SchoolYear::query()->where('start_date', '<=', $today)->where('end_date', '>=', $today)->value('id')
            ?? SchoolYear::query()->orderByDesc('start_date')->value('id');

        return (new AnalysisFilter(schoolYearId: $year))->toArray();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Filter')
                ->collapsible()
                ->compact()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])->schema([
                        Select::make('school_year_id')->label('Schuljahr')
                            ->options(fn () => SchoolYear::query()->orderByDesc('start_date')->pluck('label', 'id'))
                            ->placeholder('Alle Schuljahre')
                            ->live(),
                        Select::make('assessment_type_ids')->label('Erhebungstyp')
                            ->multiple()
                            ->options(fn () => AssessmentType::query()->orderBy('sort_order')->pluck('label', 'id'))
                            ->placeholder('Alle')
                            ->live(),
                        Select::make('test_run_ids')->label('Testdurchläufe')
                            ->multiple()
                            ->searchable()
                            ->options(fn (Get $get) => $this->testRunOptions($get('school_year_id')))
                            ->placeholder('Alle')
                            ->live(),
                        Select::make('grade_levels')->label('Jahrgang')
                            ->multiple()
                            ->options(fn (Get $get) => $this->gradeLevelOptions($get('school_year_id')))
                            ->placeholder('Alle')
                            ->live(),
                        Select::make('learning_group_ids')->label('Lerngruppen')
                            ->multiple()
                            ->searchable()
                            ->options(fn (Get $get) => $this->learningGroupOptions($get('school_year_id'), (array) $get('grade_levels')))
                            ->placeholder('Alle')
                            ->live(),
                        Select::make('genders')->label('Geschlecht')
                            ->multiple()
                            ->options(AnalysisFilter::GENDERS)
                            ->placeholder('Alle')
                            ->live(),
                        Select::make('repeater')->label('Wiederholer')
                            ->options(['0' => 'ohne Wiederholer', '1' => 'nur Wiederholer'])
                            ->placeholder('Alle')
                            ->live(),
                        Select::make('parallel_forms')->label('Parallelform')
                            ->multiple()
                            ->options(fn () => $this->parallelFormOptions())
                            ->placeholder('Alle')
                            ->live(),
                        DatePicker::make('date_from')->label('Abgegeben ab')->native(false)->displayFormat('d.m.Y')->live(),
                        DatePicker::make('date_to')->label('Abgegeben bis')->native(false)->displayFormat('d.m.Y')->live(),
                    ]),
                    Grid::make(['default' => 1, 'sm' => 3])->schema([
                        Select::make('group_by')->label('Gruppieren nach')
                            ->options(AnalysisFilter::GROUP_BY)
                            ->selectablePlaceholder(false)
                            ->live(),
                        Select::make('secondary_group_by')->label('Zusätzlich aufteilen nach')
                            ->options(fn (Get $get) => array_diff_key(AnalysisFilter::GROUP_BY, ['none' => true, (string) $get('group_by') => true]))
                            ->placeholder('nicht aufteilen')
                            ->live(),
                        Select::make('basis')->label('Datenbasis')
                            ->options(AnalysisFilter::BASIS)
                            ->selectablePlaceholder(false)
                            ->helperText('Standard: je Schüler nur der jüngste Versuch.')
                            ->live(),
                    ]),
                ]),
        ])->statePath('filters');
    }

    /**
     * Schnellwahl über der Filterzeile.
     */
    public function applyPreset(string $preset): void
    {
        $changes = match ($preset) {
            'classes' => ['group_by' => 'learning_group', 'secondary_group_by' => null],
            'gender' => ['group_by' => 'gender', 'secondary_group_by' => null],
            'class_gender' => ['group_by' => 'learning_group', 'secondary_group_by' => 'gender'],
            default => [],
        };
        $this->form->fill(array_merge((array) $this->filters, $changes));
        if ($preset === 'class_gender') {
            $this->byGender = true;
        }
    }

    public function filter(): AnalysisFilter
    {
        return AnalysisFilter::fromArray((array) $this->filters);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        return app(AnalysisDataset::class)->rows($this->filter(), auth()->user());
    }

    /**
     * Alles, was die Ansicht braucht (einmal je Render berechnet).
     *
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $filter = $this->filter();
        $rows = $this->rows();
        $dist = app(AnalysisReport::class)->groupedDistribution($rows, $filter->groupBy, $filter->secondaryGroupBy);

        return [
            'filter' => $filter,
            'dist' => $dist,
            'kpis' => AnalysisPdfRenderer::kpis($rows, $dist),
            'genderInfo' => AnalysisPdfRenderer::genderInfo($rows),
            'noScope' => app(AnalysisDataset::class)->allowedGroupIds(auth()->user()) === [],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('PDF (A4)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('primary')
                ->visible(fn () => auth()->user()?->hasPermission('print.generate') ?? false)
                ->modalHeading('Datenanalyse als PDF')
                ->modalDescription('Enthält den Gruppenvergleich mit Kennzahlen, optional Schülerlisten mit Klarnamen.')
                ->modalSubmitActionLabel('PDF erzeugen')
                ->form([
                    Radio::make('orientation')->label('Ausrichtung')
                        ->options(['portrait' => 'Hochformat', 'landscape' => 'Querformat'])
                        ->default('portrait')->inline()->required(),
                    Toggle::make('with_lists')->label('Schülerliste je Gruppe anhängen (Klarnamen)')
                        ->default(fn () => auth()->user()?->hasPermission('print.generate_with_clearname') ?? false)
                        ->disabled(fn () => ! (auth()->user()?->hasPermission('print.generate_with_clearname') ?? false))
                        ->helperText('Erfordert entsperrte Klarnamen. Der Export wird protokolliert.'),
                ])
                ->action(fn (array $data) => $this->exportPdf((string) ($data['orientation'] ?? 'portrait'), (bool) ($data['with_lists'] ?? false))),
            Action::make('exportCsv')
                ->label('CSV')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasPermission('attempts.export') ?? false)
                ->modalHeading('Datenanalyse als CSV')
                ->modalSubmitActionLabel('CSV herunterladen')
                ->form([
                    Toggle::make('with_values')->label('Einzelwerte je Schüler anhängen')->default(true),
                    Toggle::make('with_names')->label('Mit Klarnamen')
                        ->default(false)
                        ->visible(fn () => auth()->user()?->hasPermission('attempts.export_with_clearname') ?? false)
                        ->helperText('Erfordert entsperrte Klarnamen. Der Export wird protokolliert.'),
                ])
                ->action(fn (array $data) => $this->exportCsv((bool) ($data['with_values'] ?? false), (bool) ($data['with_names'] ?? false))),
        ];
    }

    /**
     * Drill-down: Schülerliste einer Gruppe (aus Boxplot-Zeile oder Tabelle).
     */
    public function groupStudentsAction(): Action
    {
        return Action::make('groupStudents')
            ->slideOver()
            ->modalWidth('3xl')
            ->modalHeading(fn (array $arguments) => $this->findGroup((string) ($arguments['group'] ?? ''))['title'] ?? 'Gruppe')
            ->modalContent(fn (array $arguments) => view('filament.pages.data-analysis-group', [
                'group' => $this->findGroup((string) ($arguments['group'] ?? '')),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Schließen');
    }

    /**
     * @return array{title: string, students: list<array<string, mixed>>}|null
     */
    private function findGroup(string $key): ?array
    {
        $filter = $this->filter();
        $dist = app(AnalysisReport::class)->groupedDistribution($this->rows(), $filter->groupBy, $filter->secondaryGroupBy);
        $group = collect($dist['groups'])->firstWhere('key', $key);
        if ($group === null) {
            return null;
        }

        return [
            'title' => trim($group['label'].($group['sublabel'] ? ' · '.$group['sublabel'] : '')).' (n = '.$group['n'].')',
            'students' => AnalysisReport::studentList($group['rows']),
        ];
    }

    public function exportPdf(string $orientation, bool $withLists): ?StreamedResponse
    {
        $user = auth()->user();
        if ($withLists) {
            if (! $user->hasPermission('print.generate_with_clearname')) {
                Notification::make()->danger()->title('Keine Berechtigung für Klarnamen-Druck')->send();

                return null;
            }
            if (! app(CryptoService::class)->isUnlocked()) {
                Notification::make()->danger()
                    ->title('Klarnamen-Session gesperrt')
                    ->body('Bitte zuerst unter „Klarnamen → Entsperren" entsperren, dann erneut exportieren – oder ohne Schülerlisten drucken.')
                    ->persistent()->send();

                return null;
            }
        }

        $filter = $this->filter();
        $rows = $this->rows();
        if ($rows->isEmpty()) {
            Notification::make()->warning()->title('Keine Daten für die gewählten Filter')->send();

            return null;
        }

        return self::runPrintAction(function () use ($filter, $rows, $user, $orientation, $withLists) {
            $renderer = app(AnalysisPdfRenderer::class);
            $html = $renderer->html($filter, $rows, $user, $orientation, $withLists, [
                'show_bands' => $this->showBands,
                'by_gender' => $this->byGender,
            ]);
            $pdf = app(GotenbergClient::class)->htmlToPdf(
                $html,
                null,
                ['preferCssPageSize' => 'true', 'printBackground' => 'true'],
                ['footer.html' => $renderer->footer($withLists)],
            );

            $fileName = 'datenanalyse-'.now()->format('Ymd-Hi').'.pdf';
            $path = 'lsp/analysis/'.now()->format('Ymd_His').'_'.$user->id.'.pdf';
            Storage::disk('local')->put($path, $pdf);
            $doc = GeneratedDocument::create([
                'file_name' => $fileName,
                'file_path' => $path,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($pdf),
                'includes_clearnames' => $withLists,
                'sha256' => hash('sha256', $pdf),
                'expires_at' => now()->addDays((int) config('lsp.pdf.document_retention_days', 30)),
                'created_by_user_id' => $user->id,
            ]);

            app(AuditLogger::class)->logUser($user, 'analysis.export_pdf', 'generated_document', $doc->id, [
                'filter' => $filter->toArray(),
                'students' => $rows->pluck('student_id')->unique()->count(),
                'orientation' => $orientation,
                'student_lists' => $withLists,
            ], includesClearnames: $withLists);

            return response()->streamDownload(fn () => print ($pdf), $fileName, ['Content-Type' => 'application/pdf']);
        }, 'PDF-Export');
    }

    public function exportCsv(bool $withValues, bool $withNames): ?StreamedResponse
    {
        $user = auth()->user();
        $withNames = $withValues && $withNames;
        if ($withNames && (! $user->hasPermission('attempts.export_with_clearname') || ! app(CryptoService::class)->isUnlocked())) {
            Notification::make()->danger()
                ->title('Klarnamen nicht verfügbar')
                ->body('Für den Export mit Namen bitte zuerst die Klarnamen entsperren.')
                ->persistent()->send();

            return null;
        }

        $filter = $this->filter();
        $rows = $this->rows();
        if ($rows->isEmpty()) {
            Notification::make()->warning()->title('Keine Daten für die gewählten Filter')->send();

            return null;
        }

        $dist = app(AnalysisReport::class)->groupedDistribution($rows, $filter->groupBy, $filter->secondaryGroupBy);
        $csv = app(AnalysisCsvExporter::class)->toCsv($dist, $filter->describe(), $withValues, $withNames);

        app(AuditLogger::class)->logUser($user, 'analysis.export_csv', null, null, [
            'filter' => $filter->toArray(),
            'students' => $rows->pluck('student_id')->unique()->count(),
            'values' => $withValues,
        ], includesClearnames: $withNames);

        return response()->streamDownload(
            fn () => print ($csv),
            'datenanalyse-'.now()->format('Ymd-Hi').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** @return array<int, string> */
    private function testRunOptions(mixed $schoolYearId): array
    {
        $allowed = app(AnalysisDataset::class)->allowedGroupIds(auth()->user());
        if ($allowed === []) {
            return [];
        }

        return TestRun::query()
            ->when(filled($schoolYearId), fn (Builder $q) => $q->where('school_year_id', (int) $schoolYearId))
            ->when($allowed !== null, fn (Builder $q) => $q->whereHas('learningGroups', fn (Builder $g) => $g->whereIn('learning_groups.id', $allowed)))
            ->orderByDesc('scheduled_for')->orderBy('name')
            ->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    private function gradeLevelOptions(mixed $schoolYearId): array
    {
        return $this->visibleGroups($schoolYearId)
            ->whereNotNull('grade_level')->where('grade_level', '!=', '')
            ->distinct()->orderBy('grade_level')
            ->pluck('grade_level')
            ->sort(SORT_NATURAL)
            ->mapWithKeys(fn ($g) => [(string) $g => 'Jahrgang '.$g])->all();
    }

    /**
     * @param  list<string>  $gradeLevels
     * @return array<int, string>
     */
    private function learningGroupOptions(mixed $schoolYearId, array $gradeLevels): array
    {
        return $this->visibleGroups($schoolYearId)
            ->when($gradeLevels !== [], fn (Builder $q) => $q->whereIn('grade_level', $gradeLevels))
            ->orderBy('group_type')->orderBy('grade_level')->orderBy('name')
            ->get(['id', 'name', 'group_type'])
            ->mapWithKeys(fn (LearningGroup $g) => [$g->id => $g->name.($g->group_type === 'kurs' ? ' (Kurs)' : '')])->all();
    }

    private function visibleGroups(mixed $schoolYearId): Builder
    {
        $allowed = app(AnalysisDataset::class)->allowedGroupIds(auth()->user());

        return LearningGroup::query()
            ->when(filled($schoolYearId), fn (Builder $q) => $q->where('school_year_id', (int) $schoolYearId))
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('id', $allowed ?: [0]));
    }

    /** @return array<string, string> */
    private function parallelFormOptions(): array
    {
        return DB::table('test_attempts')->whereNotNull('parallel_form')->where('parallel_form', '!=', '')
            ->distinct()->orderBy('parallel_form')->pluck('parallel_form')
            ->mapWithKeys(fn ($p) => [(string) $p => 'Form '.$p])->all();
    }
}
