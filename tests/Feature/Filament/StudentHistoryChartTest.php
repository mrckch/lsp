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
use App\Filament\Pages\StudentHistoryChart;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StudentHistoryChartTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacherWithoutPerm;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'TestSchule']);

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->student = new Student;
        $this->student->external_student_id = 'X1';
        $this->student->external_id_source = 'manual';
        $this->student->student_code = Student::generateUniqueCode();
        $this->student->first_name_encrypted = 'Anna';
        $this->student->last_name_encrypted = 'Müller';
        $this->student->gender = 'w';
        $this->student->save();
        $this->student->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);

        // Drei Erhebungen mit aufsteigendem LQ
        $run1 = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'R1',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $q->id, 'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        $run2 = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'R2',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $q->id, 'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        TestAttempt::create([
            'student_id' => $this->student->id, 'test_run_id' => $run1->id,
            'questionnaire_id' => $q->id, 'status' => 'abgegeben',
            'started_at' => now()->subDays(60), 'submitted_at' => now()->subDays(60),
            'time_limit_seconds' => 180, 'score_raw' => 50,
            'lq_at_submission' => 90, 'lq_current' => 90,
        ]);
        TestAttempt::create([
            'student_id' => $this->student->id, 'test_run_id' => $run2->id,
            'questionnaire_id' => $q->id, 'status' => 'abgegeben',
            'started_at' => now()->subDays(10), 'submitted_at' => now()->subDays(10),
            'time_limit_seconds' => 180, 'score_raw' => 60,
            'lq_at_submission' => 105, 'lq_current' => 105,
        ]);

        $this->teacherWithoutPerm = User::create([
            'username' => 'lehr', 'display_name' => 'L',
            'password' => Hash::make('lehr-pw-1234567890'), 'is_active' => true,
        ]);
        // Bekommt KEINE analytics.student_history-Permission (keine Klasse zugewiesen)
    }

    #[Test]
    public function admin_can_access_history_page(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue(StudentHistoryChart::canAccess());
    }

    #[Test]
    public function user_without_permission_cannot_access(): void
    {
        $this->actingAs($this->teacherWithoutPerm);
        $this->assertFalse(StudentHistoryChart::canAccess());
    }

    #[Test]
    public function chart_data_is_null_without_selected_student(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(StudentHistoryChart::class)
            ->assertSet('studentId', null)
            ->call('getChartData')
            ->assertReturned(null);
    }

    #[Test]
    public function chart_data_returns_history_for_selected_student(): void
    {
        $this->actingAs($this->admin);
        $component = Livewire::test(StudentHistoryChart::class)
            ->set('data.student_id', $this->student->id);

        $component->set('studentId', $this->student->id);
        $data = $component->instance()->getChartData();

        $this->assertNotNull($data);
        $this->assertCount(2, $data['labels']);
        $this->assertEquals([90, 105], $data['lq']);
        $this->assertEquals([50, 60], $data['raw']);
        $this->assertEquals($this->student->id, $data['student']->id);
    }

    #[Test]
    public function chart_data_returns_empty_arrays_for_student_without_attempts(): void
    {
        $other = new Student;
        $other->external_student_id = 'X2';
        $other->external_id_source = 'manual';
        $other->student_code = Student::generateUniqueCode();
        $other->first_name_encrypted = 'Bob';
        $other->last_name_encrypted = 'Schmidt';
        $other->gender = 'm';
        $other->save();

        $this->actingAs($this->admin);
        $component = Livewire::test(StudentHistoryChart::class);
        $component->set('studentId', $other->id);

        $data = $component->instance()->getChartData();
        $this->assertEmpty($data['labels']);
        $this->assertEmpty($data['lq']);
    }
}
