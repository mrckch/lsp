<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Crypto\CryptoService;
use App\Domain\Privacy\PrivacyService;
use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\Student\Models\Student;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrivacyServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Student $student;

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

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);

        $this->student = new Student;
        $this->student->external_student_id = 'X1';
        $this->student->external_id_source = 'manual';
        $this->student->student_code = Student::generateUniqueCode();
        $this->student->first_name_encrypted = 'Anna';
        $this->student->last_name_encrypted = 'Müller';
        $this->student->gender = 'w';
        $this->student->save();
        $this->student->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);
        $this->student->enrollments()->create(['school_year_id' => $sy->id, 'enrolled_at' => now()->toDateString()]);
    }

    #[Test]
    public function export_student_data_contains_all_relevant_sections(): void
    {
        $data = app(PrivacyService::class)->exportStudentData($this->student);

        $this->assertArrayHasKey('student', $data);
        $this->assertArrayHasKey('enrollments', $data);
        $this->assertArrayHasKey('memberships', $data);
        $this->assertArrayHasKey('attempts', $data);
        $this->assertArrayHasKey('audit_excerpt', $data);

        $this->assertEquals('Anna', $data['student']['first_name']);
    }

    #[Test]
    public function export_masks_clearnames_when_locked(): void
    {
        app(CryptoService::class)->lock();

        $data = app(PrivacyService::class)->exportStudentData($this->student);

        $this->assertEquals('[gesperrt]', $data['student']['first_name']);
    }

    #[Test]
    public function deletion_candidates_returns_old_archived_only(): void
    {
        // Aktiver Schüler: kein Kandidat
        $this->assertCount(0, app(PrivacyService::class)->listDeletionCandidates(0));

        $this->student->update([
            'status' => 'archiviert',
            'archived_at' => now()->subYears(6),
        ]);

        $this->assertCount(1, app(PrivacyService::class)->listDeletionCandidates(1825));
        $this->assertCount(0, app(PrivacyService::class)->listDeletionCandidates(365 * 7));
    }

    #[Test]
    public function delete_requires_confirmation(): void
    {
        $service = app(PrivacyService::class);
        $this->assertFalse($service->deleteStudent($this->student, $this->admin, 'Test', confirmed: false));
        $this->assertNotNull(Student::find($this->student->id));
    }

    #[Test]
    public function delete_soft_deletes_and_audits(): void
    {
        $service = app(PrivacyService::class);
        $this->assertTrue($service->deleteStudent($this->student, $this->admin, 'Wegen DSGVO', confirmed: true));

        $this->assertNull(Student::find($this->student->id)); // soft deleted
        $this->assertEquals(1, AuditLog::query()->where('action', 'students.delete')->count());
    }
}
