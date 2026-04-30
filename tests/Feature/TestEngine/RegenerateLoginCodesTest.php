<?php

declare(strict_types=1);

namespace Tests\Feature\TestEngine;

use App\Domain\Attempt\Models\StudentLoginCode;
use App\Domain\Attempt\TestEngine;
use App\Domain\Crypto\CryptoService;
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

class RegenerateLoginCodesTest extends TestCase
{
    use RefreshDatabase;

    private TestRun $run;

    private array $studentIds;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['is_initialized' => true]);

        $admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($admin, 'clear-pw-1234567890');
        $this->actingAs($admin);

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $group = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $admin->id,
        ]);

        $this->run = TestRun::create([
            'school_year_id' => $sy->id,
            'name' => 'Run', 'short_code' => TestRun::generateShortCode(),
            'status' => 'aktiv', 'questionnaire_id' => $q->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => false, 'allow_teacher_reset' => true,
            'created_by_user_id' => $admin->id,
        ]);
        $this->run->learningGroups()->attach($group->id);

        $this->studentIds = [];
        for ($i = 0; $i < 3; $i++) {
            $s = new Student;
            $s->external_student_id = 'X'.$i;
            $s->external_id_source = 'manual';
            $s->student_code = Student::generateUniqueCode();
            $s->first_name_encrypted = "S$i";
            $s->last_name_encrypted = 'Test';
            $s->gender = 'm';
            $s->save();
            $s->memberships()->create(['learning_group_id' => $group->id, 'school_year_id' => $sy->id]);
            $this->studentIds[] = $s->id;
        }

        app(TestEngine::class)->issueLoginCodes($this->run);
    }

    #[Test]
    public function regenerate_replaces_active_codes(): void
    {
        $oldCodes = StudentLoginCode::query()->where('test_run_id', $this->run->id)
            ->pluck('login_code')->all();

        $count = app(TestEngine::class)->regenerateActiveLoginCodes($this->run);

        $this->assertEquals(3, $count);

        $newCodes = StudentLoginCode::query()->where('test_run_id', $this->run->id)
            ->pluck('login_code')->all();

        // Alle drei wurden ersetzt
        $this->assertCount(3, array_diff($newCodes, $oldCodes));
    }

    #[Test]
    public function regenerate_skips_in_progress_and_consumed_codes(): void
    {
        $codes = StudentLoginCode::query()->where('test_run_id', $this->run->id)->get();
        $codes[0]->update(['status' => 'in_bearbeitung']);
        $codes[1]->update(['status' => 'verbraucht']);

        $oldUnchanged = $codes[0]->login_code;
        $oldVerbraucht = $codes[1]->login_code;

        $count = app(TestEngine::class)->regenerateActiveLoginCodes($this->run);

        // Nur 1 (der noch aktive) wurde rotiert
        $this->assertEquals(1, $count);
        $this->assertEquals($oldUnchanged, $codes[0]->refresh()->login_code);
        $this->assertEquals($oldVerbraucht, $codes[1]->refresh()->login_code);
    }

    #[Test]
    public function regenerated_codes_are_unique(): void
    {
        app(TestEngine::class)->regenerateActiveLoginCodes($this->run);

        $codes = StudentLoginCode::query()->where('test_run_id', $this->run->id)
            ->pluck('login_code')->all();

        $this->assertCount(3, array_unique($codes));
    }
}
