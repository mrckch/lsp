<?php

declare(strict_types=1);

namespace App\Domain\Questionnaire;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Questionnaire\Exceptions\QuestionnaireImportException;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\Models\QuestionnairePracticeQuestion;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Manueller Import von Test- und Übungsfragen aus CSV oder JSON.
 *
 * CSV (Trennzeichen ; , oder Tab, Kopfzeile optional, UTF-8 oder Windows-1252):
 *   satz;antwort;typ
 *   Ein Jahr hat zwölf Monate.;richtig;test
 *   antwort: richtig/falsch (auch r/f, ja/nein, 1/0) · typ: test (Standard) | uebung
 *
 * JSON: Liste von Fragen oder Objekt mit Stammdaten:
 *   {"name": "…", "parallel_form": "A1", "questions": [{"text": "…", "answer": "richtig"}],
 *    "practice_questions": [{"text": "…", "answer": "falsch"}]}
 *
 * Alles-oder-nichts: enthält die Datei einen Fehler, wird nichts gespeichert.
 */
final class QuestionnaireImporter
{
    public const MODE_APPEND = 'append';

    public const MODE_REPLACE = 'replace';

    public const MAX_QUESTIONS = 1000;

    private const TEXT_KEYS = ['satz', 'text', 'frage', 'question', 'question_text'];

    private const ANSWER_KEYS = ['antwort', 'loesung', 'lösung', 'answer', 'correct_answer'];

    private const TYPE_KEYS = ['typ', 'type', 'art', 'phase'];

    private const META_KEYS = [
        'name', 'description', 'parallel_form', 'grade_level_target',
        'default_time_limit_seconds', 'practice_time_seconds',
    ];

    /**
     * @return array{
     *   meta: array<string, string|int>,
     *   questions: list<array{question_text: string, correct_answer: string}>,
     *   practice: list<array{question_text: string, correct_answer: string}>,
     *   errors: list<string>,
     * }
     */
    public function parse(string $content, string $fileName): array
    {
        $content = $this->normalizeEncoding($content);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $looksLikeJson = in_array(substr(ltrim($content), 0, 1), ['{', '['], true);

        $result = $extension === 'json' || ($extension !== 'csv' && $looksLikeJson)
            ? $this->parseJson($content)
            : $this->parseCsv($content);

        if ($result['errors'] === [] && $result['questions'] === [] && $result['practice'] === []) {
            $result['errors'][] = 'Die Datei enthält keine Fragen.';
        }
        if (count($result['questions']) + count($result['practice']) > self::MAX_QUESTIONS) {
            $result['errors'][] = 'Zu viele Fragen (maximal '.self::MAX_QUESTIONS.').';
        }

        return $result;
    }

    /**
     * Legt einen neuen Fragebogen (Status "entwurf") mit den importierten Fragen an.
     * Werte aus $overrides (UI-Eingaben) haben Vorrang vor den Stammdaten der Datei.
     *
     * @param  array<string, mixed>  $parsed  Ergebnis von parse()
     * @param  array<string, mixed>  $overrides
     */
    public function createQuestionnaire(array $parsed, array $overrides, int $createdByUserId): Questionnaire
    {
        $this->assertValid($parsed);

        $meta = array_merge(
            $parsed['meta'],
            array_filter($overrides, fn ($v) => is_string($v) ? trim($v) !== '' : $v !== null),
        );
        $name = trim((string) ($meta['name'] ?? ''));
        if ($name === '') {
            throw new QuestionnaireImportException('Bitte einen Namen für den neuen Fragebogen angeben (oder "name" in der JSON-Datei setzen).');
        }

        return DB::transaction(function () use ($meta, $name, $parsed, $createdByUserId): Questionnaire {
            $questionnaire = Questionnaire::create(array_merge(
                array_intersect_key($meta, array_flip(self::META_KEYS)),
                ['name' => $name, 'status' => 'entwurf', 'created_by_user_id' => $createdByUserId],
            ));
            $this->insertQuestions($questionnaire, $parsed);

            return $questionnaire;
        });
    }

    /**
     * Importiert Fragen in einen bestehenden Fragebogen (anhängen oder ersetzen).
     * Stammdaten aus der Datei werden dabei ignoriert.
     *
     * @param  array<string, mixed>  $parsed  Ergebnis von parse()
     * @return array{questions: int, practice: int}
     */
    public function importInto(Questionnaire $questionnaire, array $parsed, string $mode): array
    {
        $this->assertValid($parsed);
        if (! in_array($mode, [self::MODE_APPEND, self::MODE_REPLACE], true)) {
            throw new \InvalidArgumentException("Unbekannter Import-Modus '$mode'.");
        }

        // Rohwerte und Normen beziehen sich auf genau diesen Fragensatz — nach dem
        // ersten Testdurchlauf darf er sich nicht mehr ändern.
        if (TestAttempt::query()->where('questionnaire_id', $questionnaire->id)->exists()) {
            throw new QuestionnaireImportException(
                'Dieser Fragebogen wurde bereits in Testdurchläufen verwendet. Damit Ergebnisse vergleichbar bleiben, '
                .'bitte die Fragen als neuen Fragebogen importieren.'
            );
        }

        return DB::transaction(function () use ($questionnaire, $parsed, $mode): array {
            if ($mode === self::MODE_REPLACE) {
                QuestionnaireQuestion::query()->where('questionnaire_id', $questionnaire->id)->delete();
                QuestionnairePracticeQuestion::query()->where('questionnaire_id', $questionnaire->id)->delete();
            }
            $this->insertQuestions($questionnaire, $parsed);

            return ['questions' => count($parsed['questions']), 'practice' => count($parsed['practice'])];
        });
    }

    public static function csvTemplate(): string
    {
        return "satz;antwort;typ\n"
            ."Die Sonne ist heiß.;richtig;uebung\n"
            ."Fische können fliegen.;falsch;uebung\n"
            ."Ein Jahr hat zwölf Monate.;richtig;test\n"
            ."Schnee ist schwarz.;falsch;test\n";
    }

    public static function jsonTemplate(): string
    {
        return json_encode([
            'name' => 'Beispiel-Fragebogen',
            'parallel_form' => 'A1',
            'grade_level_target' => '5-6',
            'default_time_limit_seconds' => 180,
            'practice_time_seconds' => 30,
            'practice_questions' => [
                ['text' => 'Die Sonne ist heiß.', 'answer' => 'richtig'],
                ['text' => 'Fische können fliegen.', 'answer' => 'falsch'],
            ],
            'questions' => [
                ['text' => 'Ein Jahr hat zwölf Monate.', 'answer' => 'richtig'],
                ['text' => 'Schnee ist schwarz.', 'answer' => 'falsch'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private function assertValid(array $parsed): void
    {
        if ($parsed['errors'] !== []) {
            throw new QuestionnaireImportException(implode("\n", $parsed['errors']));
        }
    }

    private function insertQuestions(Questionnaire $questionnaire, array $parsed): void
    {
        foreach ([QuestionnaireQuestion::class => $parsed['questions'], QuestionnairePracticeQuestion::class => $parsed['practice']] as $model => $items) {
            $sort = (int) $model::query()->where('questionnaire_id', $questionnaire->id)->max('sort_order');
            foreach ($items as $item) {
                $model::query()->create($item + [
                    'questionnaire_id' => $questionnaire->id,
                    'sort_order' => ++$sort,
                ]);
            }
        }
    }

    private function normalizeEncoding(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            // Excel unter Windows speichert CSV typischerweise als Windows-1252
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    private function emptyResult(): array
    {
        return ['meta' => [], 'questions' => [], 'practice' => [], 'errors' => []];
    }

    private function parseCsv(string $content): array
    {
        $result = $this->emptyResult();
        $lines = explode("\n", $content);
        $delimiter = $this->detectDelimiter($lines);
        $columns = ['text' => 0, 'answer' => 1, 'type' => 2];
        $firstRow = true;

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, $delimiter, '"', ''));

            if ($firstRow) {
                $firstRow = false;
                $header = $this->mapHeader($cells);
                if ($header !== null) {
                    $columns = $header;

                    continue;
                }
            }

            $this->addItem($result, [
                'text' => $cells[$columns['text']] ?? '',
                'answer' => $cells[$columns['answer']] ?? '',
                'type' => $columns['type'] !== null ? ($cells[$columns['type']] ?? '') : '',
            ], 'Zeile '.($index + 1));
        }

        return $result;
    }

    /** @param list<string> $lines */
    private function detectDelimiter(array $lines): string
    {
        $first = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $first = $line;
                break;
            }
        }

        // Semikolon zuerst: Kommas stehen häufig ungequotet in Sätzen
        return match (true) {
            str_contains($first, ';') => ';',
            str_contains($first, "\t") => "\t",
            default => ',',
        };
    }

    /**
     * @param  list<string>  $cells
     * @return array{text: int, answer: int, type: ?int}|null
     */
    private function mapHeader(array $cells): ?array
    {
        $lower = array_map(fn (string $c) => mb_strtolower($c), $cells);
        $text = $this->findColumn($lower, self::TEXT_KEYS);
        $answer = $this->findColumn($lower, self::ANSWER_KEYS);
        if ($text === null || $answer === null) {
            return null;
        }

        return ['text' => $text, 'answer' => $answer, 'type' => $this->findColumn($lower, self::TYPE_KEYS)];
    }

    /** @param list<string> $cells */
    private function findColumn(array $cells, array $keys): ?int
    {
        foreach ($cells as $i => $cell) {
            if (in_array($cell, $keys, true)) {
                return $i;
            }
        }

        return null;
    }

    private function parseJson(string $content): array
    {
        $result = $this->emptyResult();
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $result['errors'][] = 'JSON ungültig: '.$e->getMessage();

            return $result;
        }
        if (! is_array($data)) {
            $result['errors'][] = 'JSON muss ein Objekt oder eine Liste von Fragen sein.';

            return $result;
        }

        if (array_is_list($data)) {
            $this->addJsonItems($result, $data, 'questions', null);

            return $result;
        }

        foreach (self::META_KEYS as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                $result['meta'][$key] = trim((string) $data[$key]);
            }
        }
        $this->validateMeta($result);

        $this->addJsonItems($result, $data['questions'] ?? $data['fragen'] ?? [], 'questions', null);
        $this->addJsonItems($result, $data['practice_questions'] ?? $data['uebungsfragen'] ?? [], 'practice_questions', 'uebung');

        return $result;
    }

    private function addJsonItems(array &$result, mixed $items, string $label, ?string $forcedType): void
    {
        if (! is_array($items) || ! array_is_list($items)) {
            $result['errors'][] = "'".$label."' muss eine Liste sein.";

            return;
        }

        foreach ($items as $i => $item) {
            $where = $label.'['.($i + 1).']';
            if (! is_array($item)) {
                $result['errors'][] = $where.': Eintrag muss ein Objekt sein.';

                continue;
            }
            $answer = $this->pick($item, self::ANSWER_KEYS);
            if (is_bool($answer)) {
                $answer = $answer ? 'richtig' : 'falsch';
            }
            $this->addItem($result, [
                'text' => $this->pick($item, self::TEXT_KEYS),
                'answer' => $answer,
                'type' => $forcedType ?? $this->pick($item, self::TYPE_KEYS),
            ], $where);
        }
    }

    private function pick(array $item, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item)) {
                return is_scalar($item[$key]) ? $item[$key] : '';
            }
        }

        return '';
    }

    private function validateMeta(array &$result): void
    {
        foreach (['name' => 150, 'parallel_form' => 10, 'grade_level_target' => 20] as $key => $max) {
            if (isset($result['meta'][$key]) && mb_strlen((string) $result['meta'][$key]) > $max) {
                $result['errors'][] = "Feld '$key' ist länger als $max Zeichen.";
            }
        }
        foreach (['default_time_limit_seconds', 'practice_time_seconds'] as $key) {
            if (! isset($result['meta'][$key])) {
                continue;
            }
            $value = (string) $result['meta'][$key];
            if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 3600) {
                $result['errors'][] = "Feld '$key' muss eine ganze Zahl zwischen 1 und 3600 sein.";
            } else {
                $result['meta'][$key] = (int) $value;
            }
        }
    }

    /** @param array{text: mixed, answer: mixed, type: mixed} $raw */
    private function addItem(array &$result, array $raw, string $where): void
    {
        $text = trim((string) $raw['text']);
        $answerRaw = trim((string) $raw['answer']);
        $typeRaw = trim((string) $raw['type']);
        $answer = $this->normalizeAnswer($answerRaw);
        $type = $this->normalizeType($typeRaw);

        $errors = [];
        if ($text === '') {
            $errors[] = "$where: Satz fehlt.";
        } elseif (mb_strlen($text) > 1000) {
            $errors[] = "$where: Satz ist länger als 1000 Zeichen.";
        }
        if ($answer === null) {
            $errors[] = "$where: Antwort '$answerRaw' ungültig (erlaubt: richtig/falsch).";
        }
        if ($type === null) {
            $errors[] = "$where: Typ '$typeRaw' ungültig (erlaubt: test/uebung).";
        }
        if ($errors !== []) {
            array_push($result['errors'], ...$errors);

            return;
        }

        $result[$type === 'uebung' ? 'practice' : 'questions'][] = [
            'question_text' => $text,
            'correct_answer' => $answer,
        ];
    }

    private function normalizeAnswer(string $value): ?string
    {
        return match (mb_strtolower($value)) {
            'richtig', 'r', 'ja', 'j', 'wahr', 'w', 'true', '1' => 'richtig',
            'falsch', 'f', 'nein', 'n', 'false', '0' => 'falsch',
            default => null,
        };
    }

    private function normalizeType(string $value): ?string
    {
        return match (mb_strtolower($value)) {
            '', 'test', 't', 'haupt', 'hauptteil' => 'test',
            'uebung', 'übung', 'ubung', 'u', 'ü', 'practice', 'p' => 'uebung',
            default => null,
        };
    }
}
