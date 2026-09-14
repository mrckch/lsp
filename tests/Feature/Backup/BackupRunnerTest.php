<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Domain\Backup\BackupDiskFactory;
use App\Domain\Backup\BackupRunner;
use App\Domain\Backup\Models\BackupTarget;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
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

    private function makeTarget(array $attributes = [], ?string $password = 'backup-pw-12345'): BackupTarget
    {
        $target = BackupTarget::create(array_merge([
            'name' => 'Local',
            'type' => 'local',
            'is_active' => true,
            'retention_daily' => 7,
            'retention_weekly' => 4,
            'retention_monthly' => 12,
        ], $attributes));
        $target->encryption_password = $password;
        $target->save();

        return $target;
    }

    /** Ersetzt das externe Ziel (SFTP) durch die übergebene Disk. */
    private function useRemote(Filesystem $remote): void
    {
        $this->app->instance(BackupDiskFactory::class, new class($remote) extends BackupDiskFactory
        {
            public function __construct(private readonly Filesystem $remote) {}

            public function forTarget(BackupTarget $target): ?Filesystem
            {
                return $target->type === 'local' ? null : $this->remote;
            }
        });
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
    public function run_creates_successful_encrypted_backup_run(): void
    {
        $run = app(BackupRunner::class)->run($this->makeTarget());

        $this->assertEquals('success', $run->status, (string) $run->error_message);
        $this->assertNotNull($run->file_name);
        $this->assertGreaterThan(0, $run->size_bytes);
        Storage::disk('local')->assertExists('lsp/backups/'.$run->file_name);
        $this->assertStringStartsWith('ENC1:', (string) Storage::disk('local')->get('lsp/backups/'.$run->file_name));
    }

    #[Test]
    public function run_without_backup_password_fails_instead_of_writing_plaintext(): void
    {
        $run = app(BackupRunner::class)->run($this->makeTarget(password: null));

        $this->assertEquals('failed', $run->status);
        $this->assertStringContainsString('kein Backup-Passwort', (string) $run->error_message);
        $this->assertSame([], Storage::disk('local')->allFiles('lsp/backups'));
    }

    #[Test]
    public function retention_keeps_only_n_most_recent_backups(): void
    {
        $target = $this->makeTarget([
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

    #[Test]
    public function table_names_come_from_the_current_schema(): void
    {
        $tables = app(BackupRunner::class)->tableNames();

        $this->assertContains('users', $tables);
        $this->assertContains('backup_runs', $tables);
        $this->assertNotContains('sqlite_sequence', $tables);
    }

    #[Test]
    public function binary_values_are_marked_and_decoded_losslessly(): void
    {
        $binary = "\xFF\xFE\x80\x81binär";

        $encoded = BackupRunner::encodeValue($binary);

        $this->assertIsArray($encoded);
        $this->assertSame($binary, BackupRunner::decodeValue($encoded));
        $this->assertSame('Grüße', BackupRunner::encodeValue('Grüße'));
        $this->assertSame(42, BackupRunner::decodeValue(42));
    }

    #[Test]
    public function sftp_target_uploads_identical_file_to_remote(): void
    {
        $remote = Storage::fake('backup-remote');
        $this->useRemote($remote);

        $run = app(BackupRunner::class)->run($this->makeTarget(['type' => 'sftp']));

        $this->assertEquals('success', $run->status, (string) $run->error_message);
        $remote->assertExists($run->file_name);
        $this->assertSame(
            Storage::disk('local')->get('lsp/backups/'.$run->file_name),
            $remote->get($run->file_name),
        );
    }

    #[Test]
    public function failed_upload_marks_run_failed_and_keeps_local_copy(): void
    {
        $remote = Mockery::mock(Filesystem::class);
        $remote->shouldReceive('put')->andThrow(new \RuntimeException('Connection refused'));
        $this->useRemote($remote);

        $run = app(BackupRunner::class)->run($this->makeTarget(['type' => 'sftp']));

        $this->assertEquals('failed', $run->status);
        $this->assertStringContainsString('Upload zum externen Ziel fehlgeschlagen', (string) $run->error_message);
        $this->assertStringContainsString('Connection refused', (string) $run->error_message);
        Storage::disk('local')->assertExists('lsp/backups/'.$run->file_name);
    }

    #[Test]
    public function retention_also_deletes_remote_copies(): void
    {
        $remote = Storage::fake('backup-remote');
        $this->useRemote($remote);
        $target = $this->makeTarget([
            'type' => 'sftp',
            'retention_daily' => 1,
            'retention_weekly' => 0,
            'retention_monthly' => 0,
        ]);

        $first = app(BackupRunner::class)->run($target);
        usleep(10000);
        $second = app(BackupRunner::class)->run($target);

        $remote->assertMissing($first->file_name);
        $remote->assertExists($second->file_name);
    }

    #[Test]
    public function connection_test_writes_and_removes_probe_file(): void
    {
        $remote = Storage::fake('backup-remote');
        $this->useRemote($remote);

        app(BackupRunner::class)->testConnection($this->makeTarget(['type' => 'sftp']));

        $this->assertSame([], $remote->allFiles());
    }

    #[Test]
    public function backup_command_fails_when_a_backup_fails(): void
    {
        $this->makeTarget(password: null);

        $this->artisan('backup:run')
            ->expectsOutputToContain('FEHLER')
            ->assertFailed();
    }

    #[Test]
    public function backup_command_succeeds_for_healthy_targets(): void
    {
        $this->makeTarget();

        $this->artisan('backup:run')
            ->expectsOutputToContain('OK')
            ->assertSuccessful();
    }

    #[Test]
    public function backup_is_registered_in_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('backup:run')
            ->assertSuccessful();
    }
}
