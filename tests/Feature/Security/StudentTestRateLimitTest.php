<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StudentTestRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['is_initialized' => true]);
        RateLimiter::clear('throttle:10,1');
    }

    #[Test]
    public function login_endpoint_throttles_after_10_attempts(): void
    {
        // 10 Versuche dürfen durch (auch wenn sie alle fehlschlagen)
        for ($i = 0; $i < 10; $i++) {
            $this->post('/t/login', ['login_code' => 'XXXXXXXXXX'])
                ->assertStatus(302); // Redirect mit Fehler-Flash
        }

        // Versuch 11 wird gedrosselt → 429
        $this->post('/t/login', ['login_code' => 'XXXXXXXXXX'])
            ->assertStatus(429);
    }

    #[Test]
    public function answer_endpoint_has_higher_throttle_limit(): void
    {
        // 120/min: 50 Anfragen ohne Throttle möglich (auch wenn sie ohne
        // Session 401 zurückgeben — wir testen nur das Rate-Limit)
        for ($i = 0; $i < 50; $i++) {
            $r = $this->post('/t/antwort', ['question_id' => 1, 'answer' => 'richtig']);
            $this->assertNotEquals(429, $r->status(), "Iteration $i wurde unerwartet gedrosselt");
        }
    }
}
