<?php

declare(strict_types=1);

namespace Tests\Feature\Print;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserScopeAssignment;
use App\Domain\Permission\PermissionResolver;
use App\Domain\PrintJob\BulkHistoryExporter;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\Models\PrintTemplateVersion;
use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BulkHistoryExporterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacher;
    private array $studentsByName = [];
    private LearningGroup $g1;
    private LearningGroup $g2;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['school_name' => 'BSP']);
        $this->app->singleton(PermissionResolver::class, fn () => new PermissionResolver(useCache: false));

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

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $this->g1 = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $this->g2 = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5b', 'group_type' => 'klasse']);

        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);
        $run = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'R', 'short_code' => TestRun::generateShortCode(),
            'status' => 'abgeschlossen', 'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);

        $tpl = PrintTemplate::create([
            'key' => 'verlaufsdiagramm', 'name' => 'Verlauf',
            'type' => 'student_history', 'is_system' => true,
        ]);
        $version = PrintTemplateVersion::create([
            'print_template_id' => $tpl->id, 'version_number' => 1,
            'html_content' => '<h1>{{student_name}}</h1>',
            'created_by_user_id' => $this->admin->id,
        ]);
        $tpl->update(['current_version_id' => $version->id]);

        // 3 Schüler in 5a mit Versuchen, 1 in 5b mit Versuch, 1 ohne Versuch
        foreach ([['Anna', $this->g1, true], ['Bob', $this->g1, true], ['Carla', $this->g1, true],
                  ['Dora', $this->g2, true], ['Emil', $this->g1, false]] as [$name, $g, $hasAttempt]) {
            $s = $this->mkStudent($name, $g, $sy);
            $this->studentsByName[$name] = $s;
            if ($hasAttempt) {
                TestAttempt::create([
                    'student_id' => $s->id, 'test_run_id' => $run->id,
                    'questionnaire_id' => $q->id, 'status' => 'abgegeben',
                    'started_at' => now(), 'submitted_at' => now(),
                    'time_limit_seconds' => 180, 'score_raw' => 50, 'lq_at_submission' => 100, 'lq_current' => 100,
                ]);
            }
        }
    }

    private function mkStudent(string $name, LearningGroup $g, SchoolYear $sy): Student
    {
        $s = new Student;
        $s->external_student_id = $name;
        $s->external_id_source = 'manual';
        $s->student_code = Student::generateUniqueCode();
        $s->first_name_encrypted = $name;
        $s->last_name_encrypted = $name;
        $s->gender = 'w';
        $s->save();
        $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);
        $s->enrollments()->create(['school_year_id' => $sy->id, 'enrolled_at' => now()->toDateString()]);

        return $s;
    }

    private function fakeGotenberg(): GotenbergClient
    {
        return new class('http://x') extends GotenbergClient
        {
            public function htmlToPdf(string $html, ?string $css = null, array $options = []): string
            {
                return "%PDF-FAKE\n".$html;
            }
        };
    }

    #[Test]
    public function it_exports_pdfs_for_all_given_students_with_attempts(): void
    {
        $exporter = new BulkHistoryExporter($this->fakeGotenberg(), app(\App\Domain\Analytics\AnalyticsService::class));
        $ids = collect($this->studentsByName)->pluck('id')->all();

        $result = $exporter->exportFor($ids);

        $this->assertEquals(4, $result['count']);
        $this->assertEquals(1, $result['skipped']); // Emil ohne Versuche
        $this->assertEmpty($result['errors']);
        $this->assertFileExists($result['zip']);
        @unlink($result['zip']);
    }

    #[Test]
    public function scope_filter_limits_to_users_assigned_groups(): void
    {
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id, 'learning_group_id' => $this->g1->id,
        ]);

        $exporter = new BulkHistoryExporter($this->fakeGotenberg(), app(\App\Domain\Analytics\AnalyticsService::class));
        $ids = collect($this->studentsByName)->pluck('id')->all();

        $result = $exporter->exportFor($ids, forUser: $this->teacher);

        // Lehrer hat nur 5a → 3 mit Versuchen + 1 (Emil) skipped
        $this->assertEquals(3, $result['count']);
        $this->assertEquals(1, $result['skipped']);
        @unlink($result['zip']);
    }

    #[Test]
    public function it_throws_when_template_missing(): void
    {
        PrintTemplate::query()->where('key', 'verlaufsdiagramm')->delete();

        $exporter = new BulkHistoryExporter($this->fakeGotenberg(), app(\App\Domain\Analytics\AnalyticsService::class));
        $this->expectException(\RuntimeException::class);
        $exporter->exportFor([1]);
    }
}
