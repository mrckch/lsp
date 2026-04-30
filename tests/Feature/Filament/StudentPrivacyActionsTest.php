<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Privacy\PrivacyService;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain-Level-Test des Lösch- und DSGVO-Auskunftsworkflows.
 * Die UI-Aktionen (ViewStudent) verwenden diese Service-Methoden direkt.
 */
class StudentPrivacyActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true]);
        $this->app->singleton(PermissionResolver::class, fn () => new PermissionResolver(useCache: false));

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);

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
            'school_year_id' => $sy->id, 'enrolled_at' => now()->subYear()->toDateString(),
        ]);
    }

    #[Test]
    public function privacy_export_is_serializable_as_json(): void
    {
        $data = app(PrivacyService::class)->exportStudentData($this->student);
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        $this->assertNotEmpty($json);
        $this->assertStringContainsString('"student"', $json);
        $this->assertStringContainsString($this->student->student_code, $json);
    }

    #[Test]
    public function privacy_export_includes_clearname_when_unlocked(): void
    {
        $data = app(PrivacyService::class)->exportStudentData($this->student);
        $this->assertEquals('Anna', $data['student']['first_name']);
        $this->assertEquals('Müller', $data['student']['last_name']);
    }

    #[Test]
    public function privacy_export_masks_clearname_when_locked(): void
    {
        app(CryptoService::class)->lock();
        $data = app(PrivacyService::class)->exportStudentData($this->student);
        $this->assertEquals('[gesperrt]', $data['student']['first_name']);
    }

    #[Test]
    public function delete_without_confirmed_does_nothing(): void
    {
        $this->assertFalse(
            app(PrivacyService::class)->deleteStudent(
                student: $this->student, byUser: $this->admin,
                reason: 'Test', confirmed: false,
            )
        );
        $this->assertNotNull(Student::find($this->student->id));
    }

    #[Test]
    public function delete_with_confirmed_soft_deletes_and_audits(): void
    {
        $code = $this->student->student_code;

        $this->assertTrue(
            app(PrivacyService::class)->deleteStudent(
                student: $this->student, byUser: $this->admin,
                reason: 'DSGVO-Anfrage Eltern', confirmed: true,
            )
        );

        $this->assertNull(Student::find($this->student->id));

        $audit = AuditLog::query()->where('action', 'students.delete')->first();
        $this->assertNotNull($audit);
        $this->assertEquals('user', $audit->actor_type);
        $this->assertEquals($this->admin->id, $audit->actor_user_id);
        $this->assertEquals('student', $audit->entity_type);
        $this->assertEquals($code, $audit->context['student_code']);
        $this->assertEquals('DSGVO-Anfrage Eltern', $audit->context['reason']);
    }
}
