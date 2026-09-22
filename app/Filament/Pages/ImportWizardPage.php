<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Crypto\CryptoService;
use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Import\ImporterFactory;
use App\Domain\Import\Models\ImportDiffEntry;
use App\Domain\Import\Models\ImportJob;
use App\Domain\Import\Models\ImportSource;
use App\Domain\School\Models\SchoolYear;
use App\Filament\Concerns\AuthorizedPage;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

/**
 * Geführter SchiLD-CSV-Importassistent.
 *
 * Schritte:
 *   1. Upload + Quelle/Schuljahr/Gruppentyp
 *   2. Validierung & Diff-Analyse (zeigt Anlage/Update/Archivkandidaten/Fehler)
 *   3. Admin-Entscheidungen (per-Eintrag confirm/exclude)
 *   4. Commit
 */
class ImportWizardPage extends Page implements HasForms
{
    use AuthorizedPage;
    use InteractsWithForms;

    protected static function requiredPermission(): ?string
    {
        return 'import.run';
    }

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Schüler-Import';

    protected static ?string $navigationLabel = 'Import-Assistent';

    protected static string $view = 'filament.pages.import-wizard';

    public ?array $data = [];

    public ?int $jobId = null;

    public bool $committing = false;

    public int $processed = 0;

    public int $total = 0;

    public function mount(): void
    {
        $this->form->fill([
            'source_key' => 'schild_csv',
            'group_type' => 'klasse',
            'ignore_first_row' => true,
        ]);
    }

    public function form(Form $form): Form
    {
        $svwsSources = ImportSource::query()
            ->where('type', 'svws_api')
            ->where('is_active', true)
            ->pluck('name', 'id');

        return $form->schema([
            Section::make('1. Quelle & Datei')->columns(2)->schema([
                Select::make('source_key')->label('Importquelle')->required()
                    ->live()
                    ->options([
                        'schild_csv' => 'SchiLD-CSV (NRW)',
                        'svws_api' => $svwsSources->isEmpty()
                            ? 'SVWS-NRW-API (keine Quelle konfiguriert)'
                            : 'SVWS-NRW-API',
                    ])
                    ->default('schild_csv')
                    ->disableOptionWhen(fn (string $value) => $value === 'svws_api' && $svwsSources->isEmpty()),
                Select::make('school_year_id')->label('Schuljahr')->required()
                    ->options(SchoolYear::orderByDesc('start_date')->pluck('label', 'id'))
                    ->searchable(),
                Select::make('group_type')->label('Gruppentyp')->required()
                    ->options(['klasse' => 'Klassen', 'kurs' => 'Kurse']),
                Toggle::make('ignore_first_row')->label('Erste Zeile überspringen (Header)')->default(true)
                    ->visible(fn (callable $get) => $get('source_key') === 'schild_csv'),
                FileUpload::make('csv_file')->label('CSV-Datei')
                    ->required(fn (callable $get) => $get('source_key') === 'schild_csv')
                    ->visible(fn (callable $get) => $get('source_key') === 'schild_csv')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->disk('local')->directory('lsp/imports')->visibility('private')
                    ->columnSpanFull(),
                Select::make('svws_source_id')->label('SVWS-Quelle')
                    ->options($svwsSources)
                    ->required(fn (callable $get) => $get('source_key') === 'svws_api')
                    ->visible(fn (callable $get) => $get('source_key') === 'svws_api')
                    ->columnSpanFull(),
                Toggle::make('svws_only_sek_i')
                    ->label('Nur SekI-Klassen 5–10 importieren')
                    ->default(true)
                    ->helperText('Empfohlen für LSP. Schüler in höheren Stufen werden ignoriert (weder angelegt noch archiviert).')
                    ->visible(fn (callable $get) => $get('source_key') === 'svws_api')
                    ->columnSpanFull(),
            ]),
        ])->statePath('data');
    }

    public function analyze(): void
    {
        $data = $this->form->getState();

        if (! app(CryptoService::class)->isUnlocked()) {
            Notification::make()->danger()
                ->title('Klarnamen-Session muss entsperrt sein')
                ->body('Bitte zuerst Klarnamen entsperren, bevor SuS importiert werden.')->send();

            return;
        }

        $sourceKey = (string) ($data['source_key'] ?? 'schild_csv');

        if ($sourceKey === 'schild_csv') {
            $path = Storage::disk('local')->path($data['csv_file']);
            if (! is_file($path)) {
                Notification::make()->danger()->title('Datei nicht gefunden')->send();

                return;
            }
            $input = new ImportInput(
                filePath: $path,
                filename: basename($data['csv_file']),
                ignoreFirstRow: (bool) ($data['ignore_first_row'] ?? true),
            );
        } else { // svws_api
            $sourceId = (int) ($data['svws_source_id'] ?? 0);
            if ($sourceId === 0) {
                Notification::make()->danger()->title('Keine SVWS-Quelle gewählt')->send();

                return;
            }
            $onlySekI = (bool) ($data['svws_only_sek_i'] ?? true);
            $input = new ImportInput(
                filePath: '',
                filename: 'svws_api',
                sourceId: $sourceId,
                gradeFilter: $onlySekI ? ImportInput::SEK_I_GRADES : null,
            );
        }

        try {
            $importer = app(ImporterFactory::class)->make($sourceKey);
            $diff = $importer->diff($input, (int) $data['school_year_id'], $data['group_type']);
        } catch (\Throwable $e) {
            Notification::make()->danger()
                ->title('Analyse fehlgeschlagen')
                ->body($e->getMessage())
                ->send();

            return;
        }
        $this->jobId = $diff->importJobId;

        Notification::make()->success()
            ->title('Analyse abgeschlossen')
            ->body(sprintf(
                'Anlage: %d, Update: %d, Archiv: %d, Skip: %d, Fehler: %d',
                $diff->createCount, $diff->updateCount, $diff->archiveCount,
                $diff->skipCount, $diff->errorCount,
            ))->send();
    }

    public function clearnameUnlocked(): bool
    {
        return app(CryptoService::class)->isUnlocked();
    }

    public function getDiffEntries()
    {
        if (! $this->jobId) {
            return collect();
        }

        return ImportDiffEntry::query()
            ->where('import_job_id', $this->jobId)
            ->orderByRaw("CASE action WHEN 'error' THEN 0 WHEN 'archive' THEN 1 WHEN 'create' THEN 2 WHEN 'update' THEN 3 WHEN 'skip' THEN 4 ELSE 5 END")
            ->orderBy('row_number')
            ->get();
    }

    public function toggleEntry(int $entryId): void
    {
        $entry = ImportDiffEntry::query()->find($entryId);
        if (! $entry) {
            return;
        }
        $entry->update([
            'admin_decision' => $entry->admin_decision === 'confirm' ? 'exclude' : 'confirm',
        ]);
    }

    /**
     * Startet den Import: prüft die Klarnamen-Session und schaltet auf die
     * Fortschrittsanzeige. Die eigentliche Verarbeitung läuft chunk-weise in
     * processCommitChunk() (per wire:poll), damit jeder Request die entsperrte
     * Session trägt und der Fortschritt sichtbar ist.
     */
    public function startImport(): void
    {
        if (! $this->jobId) {
            return;
        }

        if (! app(CryptoService::class)->isUnlocked()) {
            Notification::make()->danger()
                ->title('Klarnamen-Session muss entsperrt sein')
                ->body('Bitte Klarnamen entsperren und den Import erneut starten.')
                ->persistent()
                ->send();

            return;
        }

        $this->total = ImportDiffEntry::where('import_job_id', $this->jobId)->count();
        $this->processed = 0;
        $this->committing = true;
    }

    public function processCommitChunk(): void
    {
        if (! $this->committing || ! $this->jobId) {
            return;
        }

        $job = ImportJob::query()->find($this->jobId);
        $sourceKey = $job?->import_source_id ? 'svws_api' : 'schild_csv';

        try {
            $progress = app(ImporterFactory::class)->make($sourceKey)->commitChunk($this->jobId, 50);
        } catch (\Throwable $e) {
            $this->committing = false;
            Notification::make()->danger()
                ->title('Import fehlgeschlagen')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->processed = $progress['processed'];
        $this->total = $progress['total'];

        if ($progress['done']) {
            $this->committing = false;
            $c = $progress['counts'];
            Notification::make()->success()
                ->title('Import abgeschlossen')
                ->body(sprintf(
                    '%d angelegt, %d aktualisiert, %d archiviert, %d übersprungen, %d fehlgeschlagen.',
                    $c['imported'], $c['updated'], $c['archived'], $c['skipped'], $c['failed'],
                ))
                ->persistent()
                ->send();

            $this->jobId = null;
        }
    }

    public function discardAnalysis(): void
    {
        if ($this->jobId) {
            ImportJob::query()->where('id', $this->jobId)->update(['status' => 'aborted']);
            $this->jobId = null;
        }
        $this->committing = false;
    }
}
