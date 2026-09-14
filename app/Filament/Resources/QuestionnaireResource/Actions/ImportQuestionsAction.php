<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuestionnaireResource\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Questionnaire\Exceptions\QuestionnaireImportException;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\QuestionnaireImporter;
use App\Filament\Resources\QuestionnaireResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Cancel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/**
 * Header-Actions für den manuellen Fragen-Import (CSV/JSON) samt Vorlagen-Download.
 */
final class ImportQuestionsAction
{
    /** Listen-Seite: Datei als neuen Fragebogen importieren. */
    public static function forNewQuestionnaire(): Action
    {
        return Action::make('importQuestionnaire')
            ->label('Importieren (CSV/JSON)')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (): bool => QuestionnaireResource::canCreate())
            ->modalHeading('Fragebogen aus Datei importieren')
            ->modalSubmitActionLabel('Importieren')
            ->form([
                self::fileField(),
                TextInput::make('name')->label('Name des Fragebogens')->maxLength(150)
                    ->helperText('Leer lassen, wenn die JSON-Datei ein Feld "name" enthält.'),
                TextInput::make('parallel_form')->label('Parallelform')->maxLength(10)->placeholder('z. B. A1'),
                TextInput::make('grade_level_target')->label('Schulstufe (Ziel)')->maxLength(20)->placeholder('5-6'),
                self::formatHelp(),
            ])
            ->action(function (array $data, Component $livewire): void {
                $parsed = self::parseUpload($data);

                try {
                    $questionnaire = app(QuestionnaireImporter::class)->createQuestionnaire($parsed, [
                        'name' => $data['name'] ?? null,
                        'parallel_form' => $data['parallel_form'] ?? null,
                        'grade_level_target' => $data['grade_level_target'] ?? null,
                    ], (int) auth()->id());
                } catch (QuestionnaireImportException $e) {
                    self::abort($e->getMessage());
                }

                self::finish($livewire, $questionnaire, $parsed, 'neu');
            });
    }

    /** Bearbeiten-Seite: Fragen in den aktuellen Fragebogen importieren. */
    public static function forExistingQuestionnaire(): Action
    {
        return Action::make('importQuestions')
            ->label('Fragen importieren')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (Questionnaire $record): bool => QuestionnaireResource::canEdit($record))
            ->modalHeading('Fragen aus Datei importieren')
            ->modalSubmitActionLabel('Importieren')
            ->form([
                self::fileField(),
                Radio::make('mode')->label('Vorhandene Fragen')->required()
                    ->default(QuestionnaireImporter::MODE_APPEND)
                    ->options([
                        QuestionnaireImporter::MODE_APPEND => 'Behalten – importierte Fragen hinten anhängen',
                        QuestionnaireImporter::MODE_REPLACE => 'Ersetzen – alle Test- und Übungsfragen werden gelöscht',
                    ]),
                self::formatHelp(),
            ])
            ->action(function (array $data, Questionnaire $record, Component $livewire): void {
                $parsed = self::parseUpload($data);

                try {
                    app(QuestionnaireImporter::class)->importInto($record, $parsed, (string) $data['mode']);
                } catch (QuestionnaireImportException $e) {
                    self::abort($e->getMessage());
                }

                self::finish($livewire, $record, $parsed, (string) $data['mode']);
            });
    }

    public static function templates(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('downloadCsvTemplate')
                ->label('CSV-Vorlage')
                ->icon('heroicon-o-table-cells')
                ->action(fn () => response()->streamDownload(
                    // BOM, damit Excel Umlaute korrekt anzeigt
                    fn () => print ("\xEF\xBB\xBF".QuestionnaireImporter::csvTemplate()),
                    'fragebogen-vorlage.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                )),
            Action::make('downloadJsonTemplate')
                ->label('JSON-Vorlage')
                ->icon('heroicon-o-code-bracket')
                ->action(fn () => response()->streamDownload(
                    fn () => print (QuestionnaireImporter::jsonTemplate()),
                    'fragebogen-vorlage.json',
                    ['Content-Type' => 'application/json'],
                )),
        ])
            ->label('Vorlagen')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->button();
    }

    private static function fileField(): FileUpload
    {
        return FileUpload::make('file')
            ->label('Datei (CSV oder JSON)')
            ->required()
            ->disk('local')->directory('lsp/imports/questionnaires')->visibility('private')
            ->storeFileNamesIn('file_name')
            ->acceptedFileTypes([
                'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                'application/json', 'text/json',
            ])
            ->maxSize(2048);
    }

    private static function formatHelp(): Placeholder
    {
        return Placeholder::make('format_help')
            ->label('Dateiformat')
            ->content(new HtmlString(
                '<strong>CSV</strong> (Trennzeichen <code>;</code>, <code>,</code> oder Tab, Kopfzeile optional): '
                .'<code>satz;antwort;typ</code> – <code>antwort</code> = richtig/falsch (auch r/f, ja/nein, 1/0), '
                .'<code>typ</code> = test (Standard) oder uebung.<br>'
                .'<strong>JSON</strong>: <code>{"name": "…", "questions": [{"text": "…", "answer": "richtig"}], '
                .'"practice_questions": […]}</code> oder nur eine Liste von Fragen.<br>'
                .'Beispieldateien gibt es unter „Vorlagen". Enthält die Datei Fehler, wird nichts gespeichert.'
            ));
    }

    /**
     * Liest die hochgeladene Datei, entfernt sie wieder und bricht bei Fehlern ab.
     */
    private static function parseUpload(array $data): array
    {
        $path = (string) (is_array($data['file']) ? reset($data['file']) : $data['file']);
        $fileName = $data['file_name'] ?? $path;
        $fileName = (string) (is_array($fileName) ? reset($fileName) : $fileName);

        $disk = Storage::disk('local');
        $content = (string) $disk->get($path);
        $disk->delete($path);

        $parsed = app(QuestionnaireImporter::class)->parse($content, $fileName);
        if ($parsed['errors'] !== []) {
            $shown = array_slice($parsed['errors'], 0, 10);
            $more = count($parsed['errors']) - count($shown);
            self::abort(implode("\n", $shown).($more > 0 ? "\n… und {$more} weitere Fehler" : ''));
        }

        return $parsed;
    }

    private static function abort(string $message): never
    {
        Notification::make()->danger()
            ->title('Import abgebrochen – es wurde nichts gespeichert')
            ->body(nl2br(e($message)))
            ->persistent()
            ->send();

        throw new Cancel;
    }

    private static function finish(Component $livewire, Questionnaire $questionnaire, array $parsed, string $mode): void
    {
        app(AuditLogger::class)->logUser(
            auth()->user(),
            'questionnaire.imported',
            entityType: 'questionnaire', entityId: $questionnaire->id,
            context: [
                'mode' => $mode,
                'questions' => count($parsed['questions']),
                'practice_questions' => count($parsed['practice']),
            ],
        );

        Notification::make()->success()
            ->title('Import erfolgreich')
            ->body(sprintf('%d Test-Fragen und %d Übungsfragen importiert.', count($parsed['questions']), count($parsed['practice'])))
            ->send();

        // Neu laden, damit das Formular die importierten Fragen zeigt
        $livewire->redirect(QuestionnaireResource::getUrl('edit', ['record' => $questionnaire]));
    }
}
