<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ViewStudentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true]);

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->student = new Student;
        $this->student->external_student_id = 'X';
        $this->student->external_id_source = 'manual';
        $this->student->student_code = Student::generateUniqueCode();
        $this->student->first_name_encrypted = 'Anna';
        $this->student->last_name_encrypted = 'Müller';
        $this->student->gender = 'w';
        $this->student->save();
        $this->student->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);
        $this->student->enrollments()->create([
            'school_year_id' => $sy->id, 'grade_level' => '5',
            'enrolled_at' => now()->subYear()->toDateString(),
        ]);

        $run = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'R',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        TestAttempt::create([
            'student_id' => $this->student->id, 'test_run_id' => $run->id,
            'questionnaire_id' => $q->id, 'status' => 'abgegeben',
            'started_at' => now()->subDays(30), 'submitted_at' => now()->subDays(30),
            'time_limit_seconds' => 180, 'score_raw' => 55, 'lq_at_submission' => 95, 'lq_current' => 95,
        ]);
        TestAttempt::create([
            'student_id' => $this->student->id, 'test_run_id' => $run->id,
            'questionnaire_id' => $q->id, 'status' => 'abgegeben',
            'started_at' => now()->subDays(7), 'submitted_at' => now()->subDays(7),
            'time_limit_seconds' => 180, 'score_raw' => 60, 'lq_at_submission' => 102, 'lq_current' => 102,
        ]);
    }

    private function makePage(Student $s): ViewStudent
    {
        $page = new ViewStudent;
        // ViewRecord erwartet Eloquent-Record als Property
        (function () use ($s) { $this->record = $s; })->call($page);

        return $page;
    }

    #[Test]
    public function view_page_loads_with_recent_attempts_and_chart_data(): void
    {
        Livewire::test(ViewStudent::class, ['record' => $this->student->id])
            ->assertSuccessful();

        $page = $this->makePage($this->student);

        $recent = $page->getRecentAttempts(5);
        $this->assertCount(2, $recent);
        $this->assertEquals(102, $recent[0]['lq']); // neuester zuerst
        $this->assertEquals(95, $recent[1]['lq']);

        $chart = $page->getMiniChart();
        $this->assertNotNull($chart);
        $this->assertEquals([95, 102], $chart['lq']); // chronologisch
    }

    #[Test]
    public function mini_chart_returns_null_for_student_without_attempts(): void
    {
        $other = new Student;
        $other->external_student_id = 'Y';
        $other->external_id_source = 'manual';
        $other->student_code = Student::generateUniqueCode();
        $other->first_name_encrypted = 'Bob';
        $other->last_name_encrypted = 'X';
        $other->gender = 'm';
        $other->save();

        $page = $this->makePage($other);

        $this->assertNull($page->getMiniChart());
        $this->assertEmpty($page->getRecentAttempts());
    }

    #[Test]
    public function enrollment_timeline_returns_school_years(): void
    {
        $page = $this->makePage($this->student);

        $timeline = $page->getEnrollmentTimeline();
        $this->assertCount(1, $timeline);
        $this->assertEquals('Y', $timeline[0]['school_year']);
        $this->assertEquals('5', $timeline[0]['grade']);
        $this->assertFalse($timeline[0]['is_repeater']);
    }
}
