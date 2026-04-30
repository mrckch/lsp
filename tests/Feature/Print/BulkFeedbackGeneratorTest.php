<?php

declare(strict_types=1);

namespace Tests\Feature\Print;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Crypto\CryptoService;
use App\Domain\FeedbackSet\Models\FeedbackSet;
use App\Domain\FeedbackSet\Models\FeedbackSetRange;
use App\Domain\PrintJob\BulkFeedbackGenerator;
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

class BulkFeedbackGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private TestRun $run;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['school_name' => 'BSP-Schule']);

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        app(CryptoService::class)->initialize($this->admin, 'pw-1234567890');
        $this->actingAs($this->admin);

        $sy = SchoolYear::create(['label' => 'Y', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31']);
        $g = LearningGroup::create(['school_year_id' => $sy->id, 'name' => '5a', 'group_type' => 'klasse']);
        $q = Questionnaire::create([
            'name' => 'Q', 'parallel_form' => 'A1', 'status' => 'aktiv',
            'created_by_user_id' => $this->admin->id,
        ]);

        $tpl = PrintTemplate::create([
            'key' => 'rueckmeldung', 'name' => 'Rückmeldung',
            'type' => 'student_feedback', 'is_system' => true,
        ]);
        $version = PrintTemplateVersion::create([
            'print_template_id' => $tpl->id, 'version_number' => 1,
            'html_content' => '<h1>{{student_name}}</h1><p>LQ: {{lq}}</p><p>{{feedback_text}}</p>',
            'css_content' => '',
            'created_by_user_id' => $this->admin->id,
        ]);
        $tpl->update(['current_version_id' => $version->id]);

        $set = FeedbackSet::create([
            'name' => 'Standard', 'status' => 'aktiv', 'is_default' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        FeedbackSetRange::create([
            'feedback_set_id' => $set->id, 'sort_order' => 1, 'name' => 'Mitte',
            'match_type' => 'lq', 'min_value' => 85, 'max_value' => 115,
            'template_html' => 'Im Norm-Bereich.', 'is_active' => true,
        ]);

        $this->run = TestRun::create([
            'school_year_id' => $sy->id, 'name' => 'R',
            'short_code' => TestRun::generateShortCode(), 'status' => 'abgeschlossen',
            'questionnaire_id' => $q->id,
            'feedback_set_id' => $set->id,
            'time_limit_seconds' => 180, 'practice_time_seconds' => 30,
            'show_score_to_student' => true, 'allow_teacher_reset' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->run->learningGroups()->attach($g->id);

        // Drei abgegebene Versuche
        foreach ([['Anna', 'A', 95], ['Bob', 'B', 100], ['Carl', 'C', 105]] as [$f, $l, $lq]) {
            $s = new Student;
            $s->external_student_id = uniqid();
            $s->external_id_source = 'manual';
            $s->student_code = Student::generateUniqueCode();
            $s->first_name_encrypted = $f;
            $s->last_name_encrypted = $l;
            $s->gender = 'w';
            $s->save();
            $s->memberships()->create(['learning_group_id' => $g->id, 'school_year_id' => $sy->id]);
            TestAttempt::create([
                'student_id' => $s->id, 'test_run_id' => $this->run->id,
                'questionnaire_id' => $q->id, 'status' => 'abgegeben',
                'started_at' => now(), 'submitted_at' => now(),
                'time_limit_seconds' => 180, 'score_raw' => 50,
                'lq_at_submission' => $lq, 'lq_current' => $lq,
            ]);
        }
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
    public function it_generates_one_pdf_per_attempt_and_zips_them(): void
    {
        $generator = new BulkFeedbackGenerator($this->fakeGotenberg());
        $result = $generator->generateForRun($this->run);

        $this->assertEquals(3, $result['count'], 'errors: '.implode(' | ', $result['errors']));
        $this->assertEmpty($result['errors']);
        $this->assertFileExists($result['zip']);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($result['zip']) === true);
        $this->assertEquals(3, $zip->numFiles);
        // Mindestens eine Datei enthält "Anna" und "LQ: 95"
        $found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = $zip->getFromIndex($i);
            if (str_contains($content, 'Anna') && str_contains($content, '95')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
        $zip->close();
        @unlink($result['zip']);
    }

    #[Test]
    public function it_throws_when_template_missing(): void
    {
        PrintTemplate::query()->where('key', 'rueckmeldung')->delete();

        $this->expectException(\RuntimeException::class);
        (new BulkFeedbackGenerator($this->fakeGotenberg()))->generateForRun($this->run);
    }

    #[Test]
    public function it_filters_to_specific_attempt_ids(): void
    {
        $only = TestAttempt::query()->where('test_run_id', $this->run->id)->limit(1)->pluck('id')->all();
        $result = (new BulkFeedbackGenerator($this->fakeGotenberg()))
            ->generateForRun($this->run, 'rueckmeldung', $only);

        $this->assertEquals(1, $result['count']);
        @unlink($result['zip']);
    }
}
