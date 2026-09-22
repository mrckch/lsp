<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\Models\UserScopeAssignment;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\AssessmentType;
use App\Domain\TestRun\Models\TestRun;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultSupportThresholdsSeeder;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Gemeinsame Testdaten der Datenanalyse:
 * Schuljahr mit 5a (Anna w 80, Ben m 100), 5b (Clara w 60, Dora w 95, Emil m 110), Kurs K1 (Anna, Emil).
 * Lehrkraft sieht nur 5a; Schulleitung ohne Scope.
 */
trait AnalysisFixtures
{
    protected User $admin;

    protected User $teacher;

    protected User $principal;

    protected SchoolYear $sy;

    protected LearningGroup $g5a;

    protected LearningGroup $g5b;

    protected LearningGroup $course;

    protected TestRun $autumn;

    protected AssessmentType $autumnType;

    /** @var array<string, Student> */
    protected array $students = [];

    protected function setUpAnalysis(): void
    {
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class, DefaultSupportThresholdsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'Testschule']);
        $this->app->singleton(PermissionResolver::class, fn () => new PermissionResolver(useCache: false));

        $this->admin = $this->user('admin', 'Admin');
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);
        $this->teacher = $this->user('lehrer', 'Lehrkraft');
        $this->principal = $this->user('sl', 'Schulleitung');

        $this->sy = SchoolYear::create(['label' => '26/27', 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addYear()->toDateString()]);
        $this->g5a = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5a', 'group_type' => 'klasse', 'grade_level' => '5']);
        $this->g5b = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5b', 'group_type' => 'klasse', 'grade_level' => '5']);
        $this->course = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => 'K1', 'group_type' => 'kurs', 'grade_level' => '5']);

        $this->autumnType = AssessmentType::create(['key' => 'herbst', 'label' => 'Herbst', 'sort_order' => 1]);
        $this->autumn = $this->makeRun('Herbst 5', $this->autumnType, [$this->g5a, $this->g5b]);

        foreach ([
            ['Anna', 'w', $this->g5a, 80], ['Ben', 'm', $this->g5a, 100],
            ['Clara', 'w', $this->g5b, 60], ['Dora', 'w', $this->g5b, 95], ['Emil', 'm', $this->g5b, 110],
        ] as [$name, $gender, $group, $lq]) {
            $this->students[$name] = $this->student($name, $gender, $group);
            $this->attempt($this->students[$name], $this->autumn, $lq);
        }
        foreach (['Anna', 'Emil'] as $name) {
            $this->students[$name]->memberships()->create(['learning_group_id' => $this->course->id, 'school_year_id' => $this->sy->id]);
        }

        UserScopeAssignment::create(['user_id' => $this->teacher->id, 'learning_group_id' => $this->g5a->id]);
        app(PermissionResolver::class)->flush();
    }

    protected function user(string $name, string $group): User
    {
        $u = User::create([
            'username' => $name, 'display_name' => ucfirst($name),
            'password' => Hash::make($name.'-pw-1234567890'), 'is_active' => true,
        ]);
        $u->userGroups()->attach(UserGroup::where('name', $group)->first()->id);

        return $u;
    }

    /** @param  list<LearningGroup>  $groups */
    protected function makeRun(string $name, AssessmentType $type, array $groups): TestRun
    {
        $q = Questionnaire::firstOrCreate(['name' => 'Q'], [
            'parallel_form' => 'A1', 'status' => 'aktiv', 'created_by_user_id' => $this->admin->id,
        ]);
        $run = TestRun::create([
            'school_year_id' => $this->sy->id, 'assessment_type_id' => $type->id, 'questionnaire_id' => $q->id,
            'name' => $name, 'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv', 'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id, 'owner_user_id' => $this->admin->id,
        ]);
        $run->learningGroups()->attach(array_map(fn (LearningGroup $g) => $g->id, $groups));

        return $run;
    }

    protected function student(string $first, string $gender, LearningGroup $group): Student
    {
        $s = new Student;
        $s->external_student_id = $first;
        $s->external_id_source = 'manual';
        $s->student_code = Student::generateUniqueCode();
        $s->first_name_encrypted = $first;
        $s->last_name_encrypted = 'Test';
        $s->gender = $gender;
        $s->save();
        $s->memberships()->create(['learning_group_id' => $group->id, 'school_year_id' => $this->sy->id]);

        return $s;
    }

    protected function attempt(Student $s, TestRun $run, int $lq, string $status = 'abgegeben', ?string $submittedAt = null): TestAttempt
    {
        return TestAttempt::create([
            'student_id' => $s->id, 'test_run_id' => $run->id, 'questionnaire_id' => $run->questionnaire_id,
            'parallel_form' => 'A1', 'status' => $status, 'started_at' => now()->subHour(),
            'submitted_at' => $submittedAt ?? now()->subMinutes(30), 'time_limit_seconds' => 180,
            'score_raw' => (int) round($lq / 4), 'lq_at_submission' => $lq, 'lq_current' => $lq,
        ]);
    }
}
