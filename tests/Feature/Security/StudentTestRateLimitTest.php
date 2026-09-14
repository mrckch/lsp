<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StudentTestRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['is_initialized' => true]);
    }

    #[Test]
    public function same_login_code_is_throttled_after_limit(): void
    {
        // 10 Versuche mit demselben Code dürfen durch (auch wenn sie fehlschlagen)
        for ($i = 0; $i < 10; $i++) {
            $this->post('/t/login', ['login_code' => 'XXXXXXXXXX'])
                ->assertStatus(302); // Redirect mit Fehler-Flash
        }

        // Versuch 11 wird gedrosselt → 429
        $this->post('/t/login', ['login_code' => 'XXXXXXXXXX'])
            ->assertStatus(429);
    }

    #[Test]
    public function whole_class_behind_one_school_ip_can_log_in(): void
    {
        // Alle Requests kommen von derselben IP (Schul-NAT) — 40 verschiedene Codes
        for ($i = 0; $i < 40; $i++) {
            $this->post('/t/login', ['login_code' => sprintf('CODE%06d', $i)])
                ->assertStatus(302);
        }
    }

    #[Test]
    public function ip_limit_still_caps_mass_code_guessing(): void
    {
        config()->set('lsp.rate_limits.student_login_per_ip', 20);

        for ($i = 0; $i < 20; $i++) {
            $this->post('/t/login', ['login_code' => sprintf('GUESS%05d', $i)])
                ->assertStatus(302);
        }

        $this->post('/t/login', ['login_code' => 'GUESS99999'])
            ->assertStatus(429);
    }

    #[Test]
    public function answer_limit_applies_per_attempt_not_per_ip(): void
    {
        config()->set('lsp.rate_limits.student_answers_per_attempt', 30);
        $payload = ['question_id' => 1, 'answer' => 'richtig'];

        // Versuch 1 schöpft sein Limit aus (401, weil es den Versuch in der Test-DB nicht gibt)
        for ($i = 0; $i < 30; $i++) {
            $status = $this->withSession(['student_attempt_id' => 1])->postJson('/t/antwort', $payload)->status();
            $this->assertNotEquals(429, $status, "Iteration $i wurde unerwartet gedrosselt");
        }
        $this->withSession(['student_attempt_id' => 1])->postJson('/t/antwort', $payload)
            ->assertStatus(429);

        // Mitschüler an derselben IP ist davon nicht betroffen
        $this->withSession(['student_attempt_id' => 2])->postJson('/t/antwort', $payload)
            ->assertStatus(401);
    }
}
