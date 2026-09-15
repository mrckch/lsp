<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Audit\AuditLogger;
use App\Domain\NormTable\LqRecalculationService;
use App\Domain\NormTable\Models\NormTable;
use App\Domain\NormTable\Models\NormTableRow;
use App\Filament\Concerns\AuthorizedResource;
use App\Filament\Resources\NormTableResource\Pages;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class NormTableResource extends Resource
{
    use AuthorizedResource;

    protected static function viewPermission(): ?string
    {
        return 'norm_tables.view';
    }

    protected static function createPermission(): ?string
    {
        return 'norm_tables.manage';
    }

    protected static function editPermission(): ?string
    {
        return 'norm_tables.manage';
    }

    protected static function deletePermission(): ?string
    {
        return 'norm_tables.manage';
    }

    protected static ?string $model = NormTable::class;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Test-Konfiguration';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Normtabelle';

    protected static ?string $pluralModelLabel = 'Normtabellen';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Stammdaten')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(150)->columnSpanFull(),
                TextInput::make('grade_level')->label('Schulstufe')->required()->maxLength(10)
                    ->placeholder('5, 6, 7, ...'),
                TextInput::make('parallel_form')->label('Parallelform')->required()->maxLength(10)
                    ->placeholder('A1, A2, ...'),
                TextInput::make('version_label')->label('Versions-Label')->maxLength(50),
                Select::make('source_type')->required()->default('manuell')
                    ->options(['csv' => 'CSV', 'xlsx' => 'XLSX', 'manuell' => 'Manuell']),
                Select::make('status')->required()->default('aktiv')
                    ->options(['entwurf' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert']),
                Toggle::make('is_active')->label('Aktiv')->default(true),
            ]),

            Section::make('Norm-Zeilen')
                ->description('Pro Rohwert ein LQ je Geschlecht. Manuell pflegen oder über die Import-Action befüllen.')
                ->schema([
                    Repeater::make('rows')->relationship()
                        ->label('Zeilen')->orderColumn('raw_score')
                        ->columns(4)
                        ->defaultItems(0)
                        ->schema([
                            TextInput::make('raw_score')->label('Rohwert')->numeric()->required(),
                            TextInput::make('quotient_male')->label('LQ männlich')->numeric()->required(),
                            TextInput::make('quotient_female')->label('LQ weiblich')->numeric()->required(),
                            TextInput::make('quotient_diverse')->label('LQ divers')->numeric(),
                        ]),
                ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('grade_level')->label('Stufe')->badge(),
                TextColumn::make('parallel_form')->label('Form')->badge(),
                TextColumn::make('rows_count')->label('Zeilen')->counts('rows'),
                BadgeColumn::make('status')
                    ->colors(['warning' => 'entwurf', 'success' => 'aktiv', 'gray' => 'archiviert']),
                IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->filters([
                SelectFilter::make('grade_level')->label('Stufe')->options(
                    NormTable::query()->pluck('grade_level', 'grade_level')->unique()->all(),
                ),
                SelectFilter::make('parallel_form')->label('Form')->options(
                    NormTable::query()->pluck('parallel_form', 'parallel_form')->unique()->all(),
                ),
            ])
            ->actions([
                EditAction::make(),
                Action::make('importCsv')
                    ->label('CSV importieren')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        FileUpload::make('csv')
                            ->label('CSV-Datei')
                            ->required()
                            ->acceptedFileTypes(['text/csv', 'text/plain'])
                            ->disk('local')->directory('lsp/imports')->visibility('private'),
                    ])
                    ->action(function (NormTable $record, array $data) {
                        $disk = Storage::disk('local');
                        try {
                            // Über die Disk lesen: deren Root ist storage/app/private, nicht storage/app
                            $content = $disk->get($data['csv']);
                        } finally {
                            // Upload nicht liegen lassen, sonst landet er in den Backups
                            $disk->delete($data['csv']);
                        }

                        $result = $content === null
                            ? ['rows' => [], 'errors' => ['Die hochgeladene Datei konnte nicht gelesen werden.']]
                            : self::parseRowsCsv($content);
                        if ($result['errors'] === [] && $result['rows'] === []) {
                            $result['errors'][] = 'Die Datei enthält keine Norm-Zeilen.';
                        }
                        if ($result['errors'] !== []) {
                            Notification::make()->danger()
                                ->title('Import fehlgeschlagen — keine Zeilen importiert')
                                ->body(implode("\n", array_slice($result['errors'], 0, 10)))
                                ->persistent()
                                ->send();

                            return;
                        }

                        DB::transaction(function () use ($record, $result) {
                            foreach ($result['rows'] as $row) {
                                NormTableRow::query()->updateOrCreate(
                                    ['norm_table_id' => $record->id, 'raw_score' => $row['raw_score']],
                                    $row,
                                );
                            }
                        });

                        Notification::make()->success()
                            ->title(count($result['rows']).' Norm-Zeilen importiert')->send();
                    }),
                Action::make('recalculateLqs')
                    ->label('Alle LQs neu berechnen')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn () => auth()->user()?->hasPermission('attempts.recalculate_lq') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Berechnet die LQs aller Versuche, die mit dieser Normtabelle '.
                        'verknüpft sind, neu. Der ursprüngliche LQ (lq_at_submission) bleibt erhalten, '.
                        'nur der lq_current wird aktualisiert. Die Änderungen werden in attempt_lq_history '.
                        'protokolliert.')
                    ->action(function (NormTable $record) {
                        $count = app(LqRecalculationService::class)
                            ->recalculateForNormTable($record, auth()->user(), 'norm_table_recalc_ui');

                        app(AuditLogger::class)->logUser(
                            auth()->user(),
                            'attempts.recalculate_lq',
                            entityType: 'norm_table', entityId: $record->id,
                            context: ['recalculated' => $count],
                        );

                        Notification::make()->success()
                            ->title("$count Versuche neu berechnet")
                            ->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    /**
     * Erwartetes CSV-Format (Kopfzeile optional, Trennzeichen ; , oder Tab, UTF-8 oder Windows-1252):
     *   raw_score;quotient_male;quotient_female[;quotient_diverse]
     *
     * Alles-oder-nichts: enthält eine Zeile ungültige Werte, wird nichts importiert.
     *
     * @return array{
     *   rows: list<array{raw_score: int, quotient_male: int, quotient_female: int, quotient_diverse: ?int}>,
     *   errors: list<string>,
     * }
     */
    private static function parseRowsCsv(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            // Excel unter Windows speichert CSV typischerweise als Windows-1252
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = array_values(array_filter(
            explode("\n", str_replace(["\r\n", "\r"], "\n", $content)),
            fn (string $line) => trim($line) !== '',
        ));
        if ($lines === []) {
            return ['rows' => [], 'errors' => []];
        }

        // Semikolon zuerst (deutsches Excel), dann Tab, sonst Komma
        $delimiter = match (true) {
            str_contains($lines[0], ';') => ';',
            str_contains($lines[0], "\t") => "\t",
            default => ',',
        };
        $isInt = fn (string $v) => preg_match('/^-?\d+$/', $v) === 1;

        $rows = [];
        $errors = [];
        foreach ($lines as $index => $line) {
            $cells = array_map('trim', str_getcsv($line, $delimiter, '"', ''));

            // Kopfzeile erkennen: erste Zeile, deren erste Spalte keine Zahl ist
            if ($index === 0 && ! $isInt($cells[0])) {
                continue;
            }

            $where = 'Zeile '.($index + 1);
            $diverse = $cells[3] ?? '';
            if (! $isInt($cells[0]) || ! $isInt($cells[1] ?? '') || ! $isInt($cells[2] ?? '')
                || ($diverse !== '' && ! $isInt($diverse))) {
                $errors[] = "$where: erwartet Rohwert;LQ männlich;LQ weiblich[;LQ divers] als ganze Zahlen.";

                continue;
            }

            // Doppelte Rohwerte: letzte Zeile gewinnt
            $rows[(int) $cells[0]] = [
                'raw_score' => (int) $cells[0],
                'quotient_male' => (int) $cells[1],
                'quotient_female' => (int) $cells[2],
                'quotient_diverse' => $diverse !== '' ? (int) $diverse : null,
            ];
        }

        return ['rows' => array_values($rows), 'errors' => $errors];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNormTables::route('/'),
            'create' => Pages\CreateNormTable::route('/create'),
            'edit' => Pages\EditNormTable::route('/{record}/edit'),
        ];
    }
}
