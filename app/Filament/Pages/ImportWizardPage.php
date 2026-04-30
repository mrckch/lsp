<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Crypto\CryptoService;
use App\Domain\Import\Adapters\SchildCsvImporter;
use App\Domain\Import\DTOs\ImportInput;
use App\Domain\Import\Models\ImportDiffEntry;
use App\Domain\Import\Models\ImportJob;
use App\Domain\School\Models\SchoolYear;
use App\Filament\Concerns\AuthorizedPage;
use Filament\Actions\Action;
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

    protected static function requiredPermission(): ?string { return 'import.run'; }

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationGroup = 'Stammdaten';
    protected static ?int $navigationSort = 5;
    protected static ?string $title = 'Schüler-Import';
    protected static ?string $navigationLabel = 'Import-Assistent';
    protected static string $view = 'filament.pages.import-wizard';

    public ?array $data = [];
    public ?int $jobId = null;

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
        return $form->schema([
            Section::make('1. Quelle & Datei')->columns(2)->schema([
                Select::make('source_key')->label('Importquelle')->required()
                    ->options([
                        'schild_csv' => 'SchiLD-CSV (NRW)',
                        'svws_api' => 'SVWS-NRW-API (noch nicht aktiv)',
                    ])
                    ->default('schild_csv')
                    ->disableOptionWhen(fn (string $value) => $value === 'svws_api'),
                Select::make('school_year_id')->label('Schuljahr')->required()
                    ->options(SchoolYear::orderByDesc('start_date')->pluck('label', 'id'))
                    ->searchable(),
                Select::make('group_type')->label('Gruppentyp')->required()
                    ->options(['klasse' => 'Klassen', 'kurs' => 'Kurse']),
                Toggle::make('ignore_first_row')->label('Erste Zeile überspringen (Header)')->default(true),
                FileUpload::make('csv_file')->label('CSV-Datei')->required()
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->disk('local')->directory('lsp/imports')->visibility('private')
                    ->columnSpanFull(),
            ]),
        ])->statePath('data');
    }

    public function analyzeAction(): Action
    {
        return Action::make('analyze')
            ->label('Analysieren (Dry-Run)')
            ->icon('heroicon-o-magnifying-glass')
            ->action('analyze');
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

        $path = storage_path('app/'.$data['csv_file']);
        if (! is_file($path)) {
            Notification::make()->danger()->title('Datei nicht gefunden')->send();

            return;
        }

        $importer = app(SchildCsvImporter::class);
        $input = new ImportInput(
            filePath: $path,
            filename: basename($data['csv_file']),
            ignoreFirstRow: (bool) ($data['ignore_first_row'] ?? true),
        );

        $diff = $importer->diff($input, (int) $data['school_year_id'], $data['group_type']);
        $this->jobId = $diff->importJobId;

        Notification::make()->success()
            ->title('Analyse abgeschlossen')
            ->body(sprintf(
                'Anlage: %d, Update: %d, Archiv: %d, Skip: %d, Fehler: %d',
                $diff->createCount, $diff->updateCount, $diff->archiveCount,
                $diff->skipCount, $diff->errorCount,
            ))->send();
    }

    public function getDiffEntries()
    {
        if (! $this->jobId) {
            return collect();
        }

        return ImportDiffEntry::query()
            ->where('import_job_id', $this->jobId)
            ->orderByRaw("FIELD(action, 'error', 'archive', 'create', 'update', 'skip')")
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

    public function commitAction(): Action
    {
        return Action::make('commit')
            ->label('Import durchführen')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Die bestätigten Aktionen werden in einer Transaktion ausgeführt. Archivierungen werden vorgenommen.')
            ->action('commit')
            ->visible(fn () => $this->jobId !== null);
    }

    public function commit(): void
    {
        if (! $this->jobId) {
            return;
        }

        $entries = ImportDiffEntry::where('import_job_id', $this->jobId)->get();
        $decisions = [];
        foreach ($entries as $e) {
            $decisions[$e->id] = $e->admin_decision;
        }

        $result = app(SchildCsvImporter::class)->commit($this->jobId, $decisions);

        Notification::make()->success()
            ->title('Import abgeschlossen')
            ->body(sprintf(
                '%d angelegt, %d aktualisiert, %d archiviert, %d übersprungen, %d fehlgeschlagen.',
                $result->imported, $result->updated, $result->archived,
                $result->skipped, $result->failed,
            ))->send();

        $this->jobId = null;
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Analyse verwerfen')
            ->color('gray')
            ->action(function () {
                if ($this->jobId) {
                    ImportJob::query()->where('id', $this->jobId)->update(['status' => 'aborted']);
                    $this->jobId = null;
                }
            })
            ->visible(fn () => $this->jobId !== null);
    }
}
