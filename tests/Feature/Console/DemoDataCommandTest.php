<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\NormTable\Models\NormTable;
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

class DemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'BSP']);
        $this->admin = User::create([
            'username' => 'admin', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($this->admin, 'clear-pass-1234');
        // Lock nach Init – Command muss selbst entsperren
        app(CryptoService::class)->lock();
    }

    #[Test]
    public function it_fails_when_setup_not_done(): void
    {
        AppSetting::singleton()->update(['is_initialized' => false]);

        $this->artisan('lsp:demo-data', ['--clearname-password' => 'clear-pass-1234'])
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_password_missing(): void
    {
        $this->artisan('lsp:demo-data')
            ->expectsOutputToContain('clearname-password ist erforderlich')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_with_wrong_password(): void
    {
        $this->artisan('lsp:demo-data', ['--clearname-password' => 'WRONG'])
            ->expectsOutputToContain('falsch')
            ->assertFailed();
    }

    #[Test]
    public function it_seeds_school_year_groups_students_and_attempts(): void
    {
        $this->artisan('lsp:demo-data', [
            '--clearname-password' => 'clear-pass-1234',
            '--students' => 12,
        ])->assertSuccessful();

        $this->assertEquals(1, SchoolYear::count());
        $this->assertEquals(3, LearningGroup::count());
        $this->assertEquals(12, Student::count());
        $this->assertEquals(1, Questionnaire::count());
        $this->assertEquals(1, NormTable::count());
        $this->assertEquals(1, TestRun::count());
        $this->assertEquals(12, StudentLoginCode::count());
        // 70% von 12 = 8 (round)
        $this->assertGreaterThanOrEqual(8, TestAttempt::count());
    }

    #[Test]
    public function reset_option_removes_previous_demo_data_before_seeding(): void
    {
        // Erste Run
        $this->artisan('lsp:demo-data', [
            '--clearname-password' => 'clear-pass-1234',
            '--students' => 9,
        ])->assertSuccessful();
        $this->assertEquals(9, Student::count());

        // Zweite Run mit --reset → wieder 9 (statt 18)
        $this->artisan('lsp:demo-data', [
            '--clearname-password' => 'clear-pass-1234',
            '--students' => 9,
            '--reset' => true,
        ])->assertSuccessful();
        $this->assertEquals(9, Student::count());
    }
}
