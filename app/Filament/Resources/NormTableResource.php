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
                        $path = storage_path('app/'.$data['csv']);
                        $count = self::importRowsCsv($record, $path);
                        Notification::make()->success()
                            ->title("$count Norm-Zeilen importiert")->send();
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
     * Erwartetes CSV-Format (mit Header):
     *   raw_score;quotient_male;quotient_female[;quotient_diverse]
     */
    private static function importRowsCsv(NormTable $table, string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }
        $handle = fopen($path, 'r');
        if (! $handle) {
            return 0;
        }
        $count = 0;
        $first = true;
        while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
            if ($first && ! is_numeric(trim($row[0] ?? ''))) {
                $first = false;

                continue;
            }
            $first = false;
            $raw = (int) trim($row[0] ?? '');
            $male = (int) trim($row[1] ?? '');
            $female = (int) trim($row[2] ?? '');
            $diverse = isset($row[3]) && trim($row[3]) !== '' ? (int) $row[3] : null;
            NormTableRow::query()->updateOrCreate(
                ['norm_table_id' => $table->id, 'raw_score' => $raw],
                ['quotient_male' => $male, 'quotient_female' => $female, 'quotient_diverse' => $diverse],
            );
            $count++;
        }
        fclose($handle);

        return $count;
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
