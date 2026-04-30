<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['is_initialized' => true]);
    }

    #[Test]
    public function security_headers_are_present_on_student_test_routes(): void
    {
        $response = $this->get('/t');

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }

    #[Test]
    public function csp_includes_self_for_default_src(): void
    {
        $response = $this->get('/t');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    #[Test]
    public function permissions_policy_disables_unused_features(): void
    {
        $response = $this->get('/t');
        $policy = $response->headers->get('Permissions-Policy');

        $this->assertStringContainsString('geolocation=()', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
        $this->assertStringContainsString('camera=()', $policy);
    }
}
