<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Attempt\TestEngine;
use App\Domain\Crypto\CryptoService;
use App\Domain\NormTable\Models\NormTable;
use App\Domain\NormTable\Models\NormTableRow;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Erzeugt Beispiel-Daten zum Ausprobieren der Anwendung
 * (Schuljahr, Klassen, Schüler mit Versuchen, Test-Konfiguration).
 *
 * Voraussetzungen:
 *  - Setup wurde durchgeführt (es existiert mind. ein Admin-User)
 *  - --clearname-password muss übergeben werden, da Schüler-Klarnamen
 *    persistiert werden
 *
 * Idempotent: kann mehrfach laufen, erkennt vorhandene Demo-Marker.
 *
 * Beispiel:
 *   php artisan lsp:demo-data --clearname-password=YourPw --students=24
 */
class DemoDataCommand extends Command
{
    protected $signature = 'lsp:demo-data
                            {--clearname-password= : Klarnamen-Passwort zum Verschlüsseln der Demo-SuS}
                            {--admin-username= : Username des Admins (Default: erster User)}
                            {--students=24 : Anzahl Schüler insgesamt}
                            {--reset : Vorhandene Demo-Daten vorher löschen}';

    protected $description = 'Erzeugt Beispiel-Daten zum Ausprobieren (Schule, SuS, Tests, Versuche).';

    private const DEMO_TAG = 'DEMO';

    public function handle(CryptoService $crypto, TestEngine $engine): int
    {
        if (! AppSetting::isInitialized()) {
            $this->error('LSP ist noch nicht eingerichtet. Bitte zuerst /setup im Browser durchklicken.');

            return self::FAILURE;
        }

        $admin = $this->resolveAdmin();
        if (! $admin) {
            $this->error('Kein Admin-User gefunden. --admin-username angeben oder einen User anlegen.');

            return self::FAILURE;
        }

        $password = (string) $this->option('clearname-password');
        if ($password === '') {
            $this->error('--clearname-password ist erforderlich (Klarnamen werden verschlüsselt gespeichert).');

            return self::FAILURE;
        }

        if (! $crypto->unlock($admin, $password)) {
            $this->error('Klarnamen-Passwort für Admin '.$admin->username.' ist falsch.');

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $this->info('Lösche vorhandene Demo-Daten ...');
            $this->reset();
        }

        $this->components->info('Erzeuge Beispieldaten ...');

        $stats = DB::transaction(function () use ($admin, $engine) {
            return $this->seedAll($admin, $engine);
        });

        $this->newLine();
        $this->components->twoColumnDetail('Schuljahr', $stats['school_year']);
        $this->components->twoColumnDetail('Lerngruppen', (string) $stats['groups']);
        $this->components->twoColumnDetail('Schüler/innen', (string) $stats['students']);
        $this->components->twoColumnDetail('Test-Run', $stats['run_name'].' ('.$stats['run_code'].')');
        $this->components->twoColumnDetail('Versuche', (string) $stats['attempts']);
        $this->newLine();
        $this->info('Fertig. Im Browser /admin öffnen, Klarnamen entsperren, Test-Run anschauen.');

        return self::SUCCESS;
    }

    private function resolveAdmin(): ?User
    {
        if ($username = $this->option('admin-username')) {
            return User::query()->where('username', $username)->first();
        }

        return User::query()->orderBy('id')->first();
    }

    private function reset(): void
    {
        // Lösche alle Demo-Schüler (Marker im external_student_id)
        $studentIds = Student::query()
            ->where('external_id_source', 'demo')
            ->pluck('id');
        if ($studentIds->isNotEmpty()) {
            StudentLoginCode::query()->whereIn('student_id', $studentIds)->delete();
            TestAttempt::query()->whereIn('student_id', $studentIds)->delete();
            DB::table('student_group_memberships')->whereIn('student_id', $studentIds)->delete();
            DB::table('student_enrollments')->whereIn('student_id', $studentIds)->delete();
            Student::query()->whereIn('id', $studentIds)->forceDelete();
        }

        // Lösche Demo-TestRuns/Questionnaires/NormTables/SchoolYears (an Marker im name)
        TestRun::query()->where('name', 'like', self::DEMO_TAG.'%')->delete();
        Questionnaire::query()->where('name', 'like', self::DEMO_TAG.'%')->delete();
        NormTable::query()->where('name', 'like', self::DEMO_TAG.'%')->delete();
        LearningGroup::query()->whereHas('schoolYear', fn ($q) => $q->where('label', 'like', self::DEMO_TAG.'%'))->delete();
        SchoolYear::query()->where('label', 'like', self::DEMO_TAG.'%')->delete();
    }

    private function seedAll(User $admin, TestEngine $engine): array
    {
        $studentTotal = max(6, (int) $this->option('students'));

        // Schuljahr
        $sy = SchoolYear::create([
            'label' => self::DEMO_TAG.' 2026/27',
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
            'is_active' => true,
        ]);

        // Drei Lerngruppen, gleichmäßig verteilt
        $groups = [];
        foreach (['5a', '5b', '6a'] as $name) {
            $groups[] = LearningGroup::create([
                'school_year_id' => $sy->id,
                'name' => self::DEMO_TAG.'-'.$name,
                'group_type' => 'klasse',
                'grade_level' => str_starts_with($name, '5') ? '5' : '6',
                'is_active' => true,
            ]);
        }

        // Fragebogen mit 20 Sätzen
        $questionnaire = Questionnaire::create([
            'name' => self::DEMO_TAG.' SLS-A1',
            'parallel_form' => 'A1',
            'grade_level_target' => '5-6',
            'default_time_limit_seconds' => 180,
            'practice_time_seconds' => 30,
            'status' => 'aktiv',
            'created_by_user_id' => $admin->id,
        ]);
        $items = $this->demoSentences();
        foreach ($items as $i => [$text, $correct]) {
            QuestionnaireQuestion::create([
                'questionnaire_id' => $questionnaire->id,
                'sort_order' => $i + 1,
                'question_text' => $text,
                'correct_answer' => $correct,
            ]);
        }

        // Normtabelle (vereinfacht, score 0..20 → LQ 60..120)
        $norm = NormTable::create([
            'name' => self::DEMO_TAG.' Norm Klasse 5/6 A1',
            'grade_level' => '5',
            'parallel_form' => 'A1',
            'is_active' => true,
            'status' => 'aktiv',
            'created_by_user_id' => $admin->id,
        ]);
        for ($s = 0; $s <= 20; $s++) {
            $lqMale = 60 + (int) round($s * 3);
            $lqFemale = 62 + (int) round($s * 3);
            NormTableRow::create([
                'norm_table_id' => $norm->id, 'raw_score' => $s,
                'quotient_male' => $lqMale, 'quotient_female' => $lqFemale,
            ]);
        }

        // Schüler erzeugen (gleichmäßig über die Lerngruppen)
        $vornamen = ['Anna', 'Ben', 'Clara', 'David', 'Emma', 'Felix', 'Greta', 'Hannes', 'Ida', 'Jonas',
            'Klara', 'Leo', 'Mia', 'Niko', 'Olivia', 'Paul', 'Quinn', 'Rosa', 'Samuel', 'Theresa',
            'Ulf', 'Vera', 'Willi', 'Xenia', 'Yannik', 'Zoe'];
        $nachnamen = ['Müller', 'Schmidt', 'Becker', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner',
            'Koch', 'Richter', 'Klein', 'Wolf', 'Krüger', 'Hartmann', 'Werner'];
        $genders = ['m', 'w', 'd', 'unbekannt'];

        $students = [];
        for ($i = 0; $i < $studentTotal; $i++) {
            $g = $groups[$i % count($groups)];
            $vorname = $vornamen[$i % count($vornamen)].($i >= count($vornamen) ? '-'.($i + 1) : '');
            $nachname = $nachnamen[$i % count($nachnamen)];
            $student = new Student;
            $student->external_student_id = 'DEMO-'.($i + 1);
            $student->external_id_source = 'demo';
            $student->student_code = Student::generateUniqueCode('DEMO');
            $student->first_name_encrypted = $vorname;
            $student->last_name_encrypted = $nachname;
            $student->gender = $genders[$i % count($genders)];
            $student->status = 'aktiv';
            $student->save();
            $student->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);
            $student->enrollments()->create([
                'school_year_id' => $sy->id,
                'grade_level' => $g->grade_level,
                'enrolled_at' => now()->subYear()->toDateString(),
            ]);
            $students[] = $student;
        }

        // TestRun anlegen (Status aktiv) + Login-Codes ausstellen
        $run = TestRun::create([
            'school_year_id' => $sy->id,
            'name' => self::DEMO_TAG.' Eingangstest 2026',
            'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv',
            'questionnaire_id' => $questionnaire->id,
            'norm_table_id' => $norm->id,
            'time_limit_seconds' => 180,
            'practice_time_seconds' => 30,
            'show_score_to_student' => true,
            'allow_teacher_reset' => true,
            'created_by_user_id' => $admin->id,
            'owner_user_id' => $admin->id,
        ]);
        foreach ($groups as $g) {
            $run->learningGroups()->attach($g->id);
        }
        $engine->issueLoginCodes($run);

        // Für 70% der SuS einen abgegebenen Versuch mit zufälligem Score
        $attempts = 0;
        $threshold = (int) round(count($students) * 0.7);
        foreach (array_slice($students, 0, $threshold) as $student) {
            $score = random_int(2, 20);
            $attempt = TestAttempt::create([
                'student_id' => $student->id, 'test_run_id' => $run->id,
                'questionnaire_id' => $questionnaire->id,
                'parallel_form' => 'A1', 'norm_table_id' => $norm->id,
                'status' => 'abgegeben',
                'started_at' => now()->subDays(random_int(1, 30)),
                'submitted_at' => now()->subDays(random_int(0, 29)),
                'time_limit_seconds' => 180,
                'score_raw' => $score,
                'lq_at_submission' => 60 + (int) round($score * 3),
                'lq_current' => 60 + (int) round($score * 3),
            ]);
            $attempts++;
        }

        return [
            'school_year' => $sy->label,
            'groups' => count($groups),
            'students' => count($students),
            'run_name' => $run->name,
            'run_code' => $run->short_code,
            'attempts' => $attempts,
        ];
    }

    /** @return list<array{0:string,1:string}>  20 Demo-Sätze. */
    private function demoSentences(): array
    {
        return [
            ['Bananen sind gelb.', 'richtig'],
            ['Schnee ist rot.', 'falsch'],
            ['In einem Wald stehen viele Bäume.', 'richtig'],
            ['Die Sonne ist viereckig.', 'falsch'],
            ['Ein Hund hat vier Beine.', 'richtig'],
            ['Im Sommer ist es kälter als im Winter.', 'falsch'],
            ['Der Mond leuchtet nachts am Himmel.', 'richtig'],
            ['Ein Auto kann fliegen.', 'falsch'],
            ['Wasser ist nass.', 'richtig'],
            ['Fische leben im Wasser.', 'richtig'],
            ['Ein Pferd ist ein Insekt.', 'falsch'],
            ['Bücher kann man lesen.', 'richtig'],
            ['Eis schmilzt in der Sonne.', 'richtig'],
            ['Eine Woche hat zehn Tage.', 'falsch'],
            ['Vögel können fliegen.', 'richtig'],
            ['Brot wird aus Steinen gebacken.', 'falsch'],
            ['Im Winter fällt manchmal Schnee.', 'richtig'],
            ['Ein Tisch hat normalerweise vier Beine.', 'richtig'],
            ['Die Erde ist eine Scheibe.', 'falsch'],
            ['Schokolade ist süß.', 'richtig'],
        ];
    }
}
