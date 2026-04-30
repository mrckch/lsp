<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SelfTestCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function selftest_passes_on_clean_test_environment(): void
    {
        // In-memory SQLite + array-Cache + sync-Queue + array-Mail =
        // alles funktional, gotenberg und app_setting werden geskippt.
        Http::preventStrayRequests();
        Http::fake([
            '*/health' => Http::response('OK', 200),
        ]);

        $this->artisan('lsp:selftest')->assertExitCode(0);
    }

    #[Test]
    public function selftest_skips_app_setting_when_not_initialized(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/health' => Http::response('OK', 200)]);

        $this->artisan('lsp:selftest --json')
            ->assertExitCode(0);
    }

    #[Test]
    public function selftest_includes_app_setting_when_initialized(): void
    {
        AppSetting::singleton()->update([
            'is_initialized' => true,
            'school_name' => 'Test-Schule',
            'initialized_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake(['*/health' => Http::response('OK', 200)]);

        $this->artisan('lsp:selftest')
            ->expectsOutputToContain('Test-Schule')
            ->assertExitCode(0);
    }

    #[Test]
    public function selftest_returns_nonzero_when_gotenberg_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/health' => Http::response('boom', 503)]);

        $exitCode = $this->artisan('lsp:selftest')->run();
        $this->assertGreaterThanOrEqual(1, $exitCode);
    }

    #[Test]
    public function json_mode_outputs_valid_json(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/health' => Http::response('OK', 200)]);

        Artisan::call('lsp:selftest', ['--json' => true]);
        $output = Artisan::output();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('failures', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertEquals(0, $decoded['failures']);
        $this->assertGreaterThan(5, count($decoded['checks']));
    }
}
