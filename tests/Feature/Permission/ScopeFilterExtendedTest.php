<?php

declare(strict_types=1);

namespace Tests\Feature\Permission;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\Models\UserScopeAssignment;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Permission\ScopeFilter;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScopeFilterExtendedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private SchoolYear $sy;

    private LearningGroup $g1;

    private LearningGroup $g2;

    private TestRun $run1;

    private TestRun $run2;

    private Student $studentA;

    private Student $studentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);

        $this->teacher = User::create([
            'username' => 't', 'display_name' => 'T',
            'password' => Hash::make('teacher-pw-1234567890'), 'is_active' => true,
        ]);
        $this->teacher->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);

        $this->sy = SchoolYear::create([
            'label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31',
        ]);
        $this->g1 = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $this->g2 = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5b', 'group_type' => 'klasse']);

        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->run1 = TestRun::create([
            'school_year_id' => $this->sy->id, 'name' => 'Run1',
            'short_code' => TestRun::generateShortCode(), 'status' => 'aktiv',
            'questionnaire_id' => $q->id, 'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id, 'owner_user_id' => $this->teacher->id,
        ]);
        $this->run1->learningGroups()->attach($this->g1->id);

        $this->run2 = TestRun::create([
            'school_year_id' => $this->sy->id, 'name' => 'Run2',
            'short_code' => TestRun::generateShortCode(), 'status' => 'aktiv',
            'questionnaire_id' => $q->id, 'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id, 'owner_user_id' => $this->admin->id,
        ]);
        $this->run2->learningGroups()->attach($this->g2->id);

        $this->studentA = $this->createStudent('A', $this->g1);
        $this->studentB = $this->createStudent('B', $this->g2);

        // Versuche
        TestAttempt::create([
            'student_id' => $this->studentA->id, 'test_run_id' => $this->run1->id,
            'questionnaire_id' => $q->id, 'status' => 'abgegeben',
            'started_at' => now(), 'submitted_at' => now(),
            'time_limit_seconds' => 180, 'score_raw' => 50, 'lq_at_submission' => 95, 'lq_current' => 95,
        ]);
        TestAttempt::create([
            'student_id' => $this->studentB->id, 'test_run_id' => $this->run2->id,
            'questionnaire_id' => $q->id, 'status' => 'abgegeben',
            'started_at' => now(), 'submitted_at' => now(),
            'time_limit_seconds' => 180, 'score_raw' => 60, 'lq_at_submission' => 105, 'lq_current' => 105,
        ]);

        // Login-Codes
        StudentLoginCode::create([
            'student_id' => $this->studentA->id, 'test_run_id' => $this->run1->id,
            'login_code' => StudentLoginCode::generateUniqueCode(), 'status' => 'aktiv',
            'issued_at' => now(),
        ]);
        StudentLoginCode::create([
            'student_id' => $this->studentB->id, 'test_run_id' => $this->run2->id,
            'login_code' => StudentLoginCode::generateUniqueCode(), 'status' => 'aktiv',
            'issued_at' => now(),
        ]);

        app(PermissionResolver::class)->flush();
    }

    private function createStudent(string $name, LearningGroup $g): Student
    {
        $s = new Student;
        $s->external_student_id = uniqid();
        $s->external_id_source = 'manual';
        $s->student_code = Student::generateUniqueCode();
        $s->first_name_encrypted = $name;
        $s->last_name_encrypted = $name;
        $s->gender = 'w';
        $s->save();
        $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $this->sy->id]);

        return $s;
    }

    #[Test]
    public function teacher_with_scope_only_sees_own_learning_group(): void
    {
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id,
            'learning_group_id' => $this->g1->id,
        ]);
        app(PermissionResolver::class)->flush($this->teacher);

        $filter = app(ScopeFilter::class);
        $groups = $filter->applyToLearningGroups(LearningGroup::query(), $this->teacher)->get();

        $this->assertCount(1, $groups);
        $this->assertEquals($this->g1->id, $groups->first()->id);
    }

    #[Test]
    public function teacher_with_scope_only_sees_test_runs_with_assigned_groups(): void
    {
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id,
            'learning_group_id' => $this->g1->id,
        ]);
        app(PermissionResolver::class)->flush($this->teacher);

        $runs = app(ScopeFilter::class)->applyToTestRuns(TestRun::query(), $this->teacher)->get();
        $this->assertCount(1, $runs);
        $this->assertEquals($this->run1->id, $runs->first()->id);
    }

    #[Test]
    public function teacher_with_scope_only_sees_attempts_of_own_students(): void
    {
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id,
            'learning_group_id' => $this->g1->id,
        ]);
        app(PermissionResolver::class)->flush($this->teacher);

        $attempts = app(ScopeFilter::class)->applyToAttempts(TestAttempt::query(), $this->teacher)->get();
        $this->assertCount(1, $attempts);
        $this->assertEquals($this->studentA->id, $attempts->first()->student_id);
    }

    #[Test]
    public function teacher_with_scope_only_sees_login_codes_of_own_students(): void
    {
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id,
            'learning_group_id' => $this->g1->id,
        ]);
        app(PermissionResolver::class)->flush($this->teacher);

        $codes = app(ScopeFilter::class)->applyToLoginCodes(StudentLoginCode::query(), $this->teacher)->get();
        $this->assertCount(1, $codes);
        $this->assertEquals($this->studentA->id, $codes->first()->student_id);
    }

    #[Test]
    public function ungescoped_user_sees_everything(): void
    {
        // Admin hat keine Scope-Assignments → ungescoped
        $filter = app(ScopeFilter::class);
        $this->assertCount(2, $filter->applyToTestRuns(TestRun::query(), $this->admin)->get());
        $this->assertCount(2, $filter->applyToAttempts(TestAttempt::query(), $this->admin)->get());
        $this->assertCount(2, $filter->applyToLoginCodes(StudentLoginCode::query(), $this->admin)->get());
    }

    #[Test]
    public function can_see_learning_group_check(): void
    {
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id,
            'learning_group_id' => $this->g1->id,
        ]);
        app(PermissionResolver::class)->flush($this->teacher);

        $filter = app(ScopeFilter::class);
        $this->assertTrue($filter->canSeeLearningGroup($this->teacher, $this->g1->id));
        $this->assertFalse($filter->canSeeLearningGroup($this->teacher, $this->g2->id));
        $this->assertTrue($filter->canSeeLearningGroup($this->admin, $this->g1->id));
        $this->assertTrue($filter->canSeeLearningGroup($this->admin, $this->g2->id));
    }
}
