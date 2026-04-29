<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Domain\Backup\BackupRunner;
use App\Domain\Backup\Models\BackupTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackupRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    #[Test]
    public function encrypt_decrypt_roundtrip_with_password(): void
    {
        $runner = app(BackupRunner::class);
        $payload = '{"hello":"world"}';
        $cipher = $runner->encrypt($payload, 'secret-password-1234');
        $this->assertStringStartsWith('ENC1:', $cipher);
        $this->assertEquals($payload, $runner->decrypt($cipher, 'secret-password-1234'));
    }

    #[Test]
    public function decrypt_fails_with_wrong_password(): void
    {
        $runner = app(BackupRunner::class);
        $cipher = $runner->encrypt('data', 'right-password-12345');
        $this->expectException(\RuntimeException::class);
        $runner->decrypt($cipher, 'wrong-password-12345');
    }

    #[Test]
    public function noenc_when_no_password_set(): void
    {
        $runner = app(BackupRunner::class);
        $cipher = $runner->encrypt('plain', '');
        $this->assertStringStartsWith('NOENC:', $cipher);
        $this->assertEquals('plain', $runner->decrypt($cipher, ''));
    }

    #[Test]
    public function run_creates_successful_backup_run(): void
    {
        $target = BackupTarget::create([
            'name' => 'Local',
            'type' => 'local',
            'is_active' => true,
            'retention_daily' => 7,
            'retention_weekly' => 4,
            'retention_monthly' => 12,
        ]);

        $run = app(BackupRunner::class)->run($target);

        $this->assertEquals('success', $run->status);
        $this->assertNotNull($run->file_name);
        $this->assertGreaterThan(0, $run->size_bytes);
        Storage::disk('local')->assertExists('lsp/backups/'.$run->file_name);
    }

    #[Test]
    public function retention_keeps_only_n_most_recent_backups(): void
    {
        $target = BackupTarget::create([
            'name' => 'Local',
            'type' => 'local',
            'is_active' => true,
            'retention_daily' => 1,
            'retention_weekly' => 0,
            'retention_monthly' => 0,
        ]);

        for ($i = 0; $i < 3; $i++) {
            app(BackupRunner::class)->run($target);
            usleep(10000);
        }

        $remaining = $target->runs()->where('status', 'success')->count();
        $this->assertEquals(1, $remaining);
    }
}
