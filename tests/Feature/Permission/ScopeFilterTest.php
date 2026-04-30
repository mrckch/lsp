<?php

declare(strict_types=1);

namespace Tests\Feature\Permission;

use App\Domain\Crypto\CryptoService;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\Models\UserScopeAssignment;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Permission\ScopeFilter;
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

class ScopeFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private SchoolYear $sy;

    private LearningGroup $g1;

    private LearningGroup $g2;

    private Student $studentA;

    private Student $studentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(DefaultUserGroupsSeeder::class);

        $admin = User::create([
            'username' => 'admin', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($admin, 'clear-pw-1234567890');
        $this->actingAs($admin);

        $this->teacher = User::create([
            'username' => 'lehrer', 'display_name' => 'L',
            'password' => Hash::make('teacher-pw-1234567890'), 'is_active' => true,
        ]);
        $this->teacher->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);

        $this->sy = SchoolYear::create([
            'label' => '2026/27', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31',
        ]);
        $this->g1 = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $this->g2 = LearningGroup::create(['school_year_id' => $this->sy->id, 'name' => '5b', 'group_type' => 'klasse']);

        $this->studentA = $this->createStudent('Anna', 'A', $this->g1);
        $this->studentB = $this->createStudent('Bob', 'B', $this->g2);
    }

    private function createStudent(string $first, string $last, LearningGroup $group): Student
    {
        $s = new Student;
        $s->external_student_id = uniqid();
        $s->external_id_source = 'manual';
        $s->student_code = Student::generateUniqueCode();
        $s->first_name_encrypted = $first;
        $s->last_name_encrypted = $last;
        $s->gender = 'unbekannt';
        $s->save();
        $s->enrollments()->create([
            'school_year_id' => $this->sy->id,
            'enrolled_at' => now()->toDateString(),
        ]);
        $s->memberships()->create([
            'learning_group_id' => $group->id,
            'school_year_id' => $this->sy->id,
        ]);

        return $s;
    }

    #[Test]
    public function teacher_without_scope_sees_all_students(): void
    {
        $filter = app(ScopeFilter::class);
        $count = $filter->applyToStudents(Student::query(), $this->teacher)->count();
        $this->assertEquals(2, $count);
    }

    #[Test]
    public function teacher_with_scope_sees_only_assigned_groups(): void
    {
        UserScopeAssignment::create([
            'user_id' => $this->teacher->id,
            'learning_group_id' => $this->g1->id,
        ]);

        $filter = new ScopeFilter(
            new PermissionResolver(useCache: false),
        );
        $students = $filter->applyToStudents(Student::query(), $this->teacher)->get();

        $this->assertCount(1, $students);
        $this->assertEquals($this->studentA->id, $students->first()->id);
    }
}
