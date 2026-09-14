<?php

declare(strict_types=1);

namespace Tests\Feature\Questionnaire;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\Questionnaire\Exceptions\QuestionnaireImportException;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\Models\QuestionnairePracticeQuestion;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use App\Domain\Questionnaire\QuestionnaireImporter;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuestionnaireImporterTest extends TestCase
{
    use RefreshDatabase;

    private QuestionnaireImporter $importer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = app(QuestionnaireImporter::class);
        $this->user = User::create([
            'username' => 'lehrkraft', 'display_name' => 'L',
            'password' => Hash::make('pw-1234567890'), 'is_active' => true,
        ]);
    }

    private function makeQuestionnaire(): Questionnaire
    {
        return Questionnaire::create([
            'name' => 'Bestand', 'status' => 'entwurf', 'created_by_user_id' => $this->user->id,
        ]);
    }

    #[Test]
    public function csv_with_header_splits_test_and_practice_questions(): void
    {
        $parsed = $this->importer->parse(
            "Satz;Antwort;Typ\nDie Sonne ist heiß.;richtig;Übung\nSchnee ist schwarz.;falsch;\nEin Jahr hat zwölf Monate.;R;test\n",
            'fragen.csv',
        );

        $this->assertSame([], $parsed['errors']);
        $this->assertSame([
            ['question_text' => 'Schnee ist schwarz.', 'correct_answer' => 'falsch'],
            ['question_text' => 'Ein Jahr hat zwölf Monate.', 'correct_answer' => 'richtig'],
        ], $parsed['questions']);
        $this->assertSame([
            ['question_text' => 'Die Sonne ist heiß.', 'correct_answer' => 'richtig'],
        ], $parsed['practice']);
    }

    #[Test]
    public function csv_without_header_uses_comma_and_respects_quotes(): void
    {
        $parsed = $this->importer->parse("Der Hund bellt.,ja\n\"Kalt, nass und grau.\",nein\n", 'fragen.txt');

        $this->assertSame([], $parsed['errors']);
        $this->assertSame([
            ['question_text' => 'Der Hund bellt.', 'correct_answer' => 'richtig'],
            ['question_text' => 'Kalt, nass und grau.', 'correct_answer' => 'falsch'],
        ], $parsed['questions']);
    }

    #[Test]
    public function csv_header_columns_may_be_reordered(): void
    {
        $parsed = $this->importer->parse("antwort;satz\nfalsch;Fische fliegen.\n", 'fragen.csv');

        $this->assertSame([['question_text' => 'Fische fliegen.', 'correct_answer' => 'falsch']], $parsed['questions']);
    }

    #[Test]
    public function invalid_rows_are_reported_with_line_numbers(): void
    {
        $parsed = $this->importer->parse("satz;antwort\nGut.;richtig\n;falsch\nText;vielleicht\n", 'fragen.csv');

        $this->assertContains('Zeile 3: Satz fehlt.', $parsed['errors']);
        $this->assertContains("Zeile 4: Antwort 'vielleicht' ungültig (erlaubt: richtig/falsch).", $parsed['errors']);
    }

    #[Test]
    public function windows_1252_csv_is_converted_to_utf8(): void
    {
        $csv = mb_convert_encoding("Über den Fluß.;richtig\n", 'Windows-1252', 'UTF-8');

        $parsed = $this->importer->parse($csv, 'excel.csv');

        $this->assertSame('Über den Fluß.', $parsed['questions'][0]['question_text']);
    }

    #[Test]
    public function utf8_bom_does_not_break_header_detection(): void
    {
        $parsed = $this->importer->parse("\xEF\xBB\xBFsatz;antwort\nA.;richtig\n", 'fragen.csv');

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(1, $parsed['questions']);
    }

    #[Test]
    public function json_object_provides_meta_and_practice_questions(): void
    {
        $json = json_encode([
            'name' => 'Form A1',
            'parallel_form' => 'A1',
            'default_time_limit_seconds' => 200,
            'questions' => [
                ['text' => 'A.', 'answer' => 'richtig'],
                ['satz' => 'B.', 'antwort' => 'f'],
            ],
            'practice_questions' => [
                ['text' => 'Ü.', 'answer' => false],
            ],
        ]);

        $parsed = $this->importer->parse($json, 'fragen.json');

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('Form A1', $parsed['meta']['name']);
        $this->assertSame('A1', $parsed['meta']['parallel_form']);
        $this->assertSame(200, $parsed['meta']['default_time_limit_seconds']);
        $this->assertCount(2, $parsed['questions']);
        $this->assertSame([['question_text' => 'Ü.', 'correct_answer' => 'falsch']], $parsed['practice']);
    }

    #[Test]
    public function json_list_with_boolean_answers_and_types(): void
    {
        $json = json_encode([
            ['text' => 'A.', 'answer' => true],
            ['text' => 'B.', 'answer' => false, 'type' => 'uebung'],
        ]);

        $parsed = $this->importer->parse($json, 'upload.json');

        $this->assertSame([['question_text' => 'A.', 'correct_answer' => 'richtig']], $parsed['questions']);
        $this->assertSame([['question_text' => 'B.', 'correct_answer' => 'falsch']], $parsed['practice']);
    }

    #[Test]
    public function invalid_json_and_empty_files_are_rejected(): void
    {
        $this->assertStringStartsWith('JSON ungültig', $this->importer->parse('{"questions": [', 'f.json')['errors'][0]);
        $this->assertSame(['Die Datei enthält keine Fragen.'], $this->importer->parse("satz;antwort\n", 'f.csv')['errors']);
    }

    #[Test]
    public function invalid_meta_values_are_rejected(): void
    {
        $json = json_encode([
            'name' => 'X',
            'default_time_limit_seconds' => 'drei Minuten',
            'questions' => [['text' => 'A.', 'answer' => 'richtig']],
        ]);

        $parsed = $this->importer->parse($json, 'f.json');

        $this->assertContains(
            "Feld 'default_time_limit_seconds' muss eine ganze Zahl zwischen 1 und 3600 sein.",
            $parsed['errors'],
        );
    }

    #[Test]
    public function shipped_templates_parse_without_errors(): void
    {
        $csv = $this->importer->parse("\xEF\xBB\xBF".QuestionnaireImporter::csvTemplate(), 'vorlage.csv');
        $json = $this->importer->parse(QuestionnaireImporter::jsonTemplate(), 'vorlage.json');

        foreach ([$csv, $json] as $parsed) {
            $this->assertSame([], $parsed['errors']);
            $this->assertCount(2, $parsed['questions']);
            $this->assertCount(2, $parsed['practice']);
        }
    }

    #[Test]
    public function create_questionnaire_uses_file_meta_with_ui_overrides(): void
    {
        $parsed = $this->importer->parse(QuestionnaireImporter::jsonTemplate(), 'vorlage.json');

        $q = $this->importer->createQuestionnaire(
            $parsed,
            ['name' => 'Aus der UI', 'parallel_form' => '', 'grade_level_target' => null],
            $this->user->id,
        );

        $this->assertSame('Aus der UI', $q->name);
        $this->assertSame('A1', $q->parallel_form); // leere UI-Eingabe → Wert aus Datei
        $this->assertSame('entwurf', $q->status);
        $this->assertEquals($this->user->id, $q->created_by_user_id);
        $this->assertEquals([1, 2], $q->questions()->pluck('sort_order')->all());
        $this->assertCount(2, $q->practiceQuestions()->get());
    }

    #[Test]
    public function create_questionnaire_requires_a_name(): void
    {
        $parsed = $this->importer->parse("A.;richtig\n", 'f.csv');

        $this->expectException(QuestionnaireImportException::class);
        $this->importer->createQuestionnaire($parsed, [], $this->user->id);
    }

    #[Test]
    public function append_continues_sort_order_after_existing_questions(): void
    {
        $q = $this->makeQuestionnaire();
        QuestionnaireQuestion::create([
            'questionnaire_id' => $q->id, 'sort_order' => 1, 'question_text' => 'Alt.', 'correct_answer' => 'richtig',
        ]);

        $result = $this->importer->importInto(
            $q,
            $this->importer->parse("Neu 1.;falsch\nNeu 2.;richtig\n", 'f.csv'),
            QuestionnaireImporter::MODE_APPEND,
        );

        $this->assertSame(['questions' => 2, 'practice' => 0], $result);
        $this->assertEquals([1, 2, 3], $q->questions()->pluck('sort_order')->all());
        $this->assertSame(['Alt.', 'Neu 1.', 'Neu 2.'], $q->questions()->pluck('question_text')->all());
    }

    #[Test]
    public function replace_removes_existing_test_and_practice_questions(): void
    {
        $q = $this->makeQuestionnaire();
        QuestionnaireQuestion::create([
            'questionnaire_id' => $q->id, 'sort_order' => 1, 'question_text' => 'Alt.', 'correct_answer' => 'richtig',
        ]);
        QuestionnairePracticeQuestion::create([
            'questionnaire_id' => $q->id, 'sort_order' => 1, 'question_text' => 'Übung alt.', 'correct_answer' => 'falsch',
        ]);

        $this->importer->importInto($q, $this->importer->parse("Neu.;falsch\n", 'f.csv'), QuestionnaireImporter::MODE_REPLACE);

        $this->assertSame(['Neu.'], $q->questions()->pluck('question_text')->all());
        $this->assertEquals([1], $q->questions()->pluck('sort_order')->all());
        $this->assertSame(0, $q->practiceQuestions()->count());
    }

    #[Test]
    public function import_with_errors_writes_nothing(): void
    {
        $q = $this->makeQuestionnaire();
        $parsed = $this->importer->parse("Gut.;richtig\nKaputt.;?\n", 'f.csv');

        try {
            $this->importer->importInto($q, $parsed, QuestionnaireImporter::MODE_APPEND);
            $this->fail('Import mit Fehlern hätte abgelehnt werden müssen.');
        } catch (QuestionnaireImportException $e) {
            $this->assertStringContainsString('Zeile 2', $e->getMessage());
        }

        $this->assertSame(0, QuestionnaireQuestion::query()->count());
    }

    #[Test]
    public function questionnaire_used_in_attempts_cannot_be_changed(): void
    {
        app(CryptoService::class)->initialize($this->user, 'pw-1234567890');
        $this->actingAs($this->user);

        $q = $this->makeQuestionnaire();
        $schoolYear = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $run = TestRun::create([
            'school_year_id' => $schoolYear->id, 'name' => 'R1', 'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv', 'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->user->id,
        ]);
        $student = new Student;
        $student->external_student_id = uniqid();
        $student->external_id_source = 'manual';
        $student->student_code = Student::generateUniqueCode();
        $student->first_name_encrypted = 'Anna';
        $student->last_name_encrypted = 'A';
        $student->gender = 'w';
        $student->save();
        TestAttempt::create([
            'student_id' => $student->id, 'test_run_id' => $run->id, 'questionnaire_id' => $q->id,
            'status' => 'abgegeben', 'started_at' => now(), 'time_limit_seconds' => 180,
        ]);

        $this->expectException(QuestionnaireImportException::class);
        $this->expectExceptionMessage('bereits in Testdurchläufen verwendet');
        $this->importer->importInto($q, $this->importer->parse("Neu.;richtig\n", 'f.csv'), QuestionnaireImporter::MODE_APPEND);
    }
}
