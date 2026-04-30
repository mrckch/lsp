<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Backup\BackupRestorer;
use App\Domain\Backup\BackupRunner;
use App\Domain\Backup\Models\BackupTarget;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fake-Disk isoliert pro Test — kein Cleanup nötig.
        // Storage::fake() erstellt einen FakeFilesystemAdapter mit eigenem Pfad,
        // den BackupRestorer korrekt via Storage::disk('local')->path() auflöst.
        Storage::fake('local');
    }

    private function makeBackupTarget(string $password = ''): BackupTarget
    {
        $t = BackupTarget::create([
            'name' => 'Local',
            'type' => 'local',
            'is_active' => true,
            'retention_daily' => 7,
            'retention_weekly' => 4,
            'retention_monthly' => 12,
        ]);
        // encryption_password ist NICHT fillable; via Attribute-Mutator setzen
        if ($password !== '') {
            $t->encryption_password = $password;
            $t->save();
        }

        return $t;
    }

    private function backupAbsolutePath(string $fileName): string
    {
        return Storage::disk('local')->path('lsp/backups/'.$fileName);
    }

    #[Test]
    public function full_roundtrip_restores_users_and_settings(): void
    {
        // 1. Daten anlegen
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'Vorher-Schule']);
        $user = User::create([
            'username' => 'restored', 'display_name' => 'Restored U.',
            'password' => Hash::make('pw-1234567890'), 'is_active' => true,
        ]);
        $userId = $user->id;
        $userIdHash = $user->password;

        // 2. Backup erstellen
        $target = $this->makeBackupTarget(password: 'backup-pw-12345');
        $run = app(BackupRunner::class)->run($target);
        $this->assertEquals('success', $run->status);

        // 3. DB-Daten zerstören (User löschen + Setting verändern)
        DB::table('users')->where('id', $userId)->delete();
        AppSetting::singleton()->update(['school_name' => 'Nachher-Schule']);
        $this->assertEquals(0, User::query()->where('id', $userId)->count());

        // 4. Restore
        $result = app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'backup-pw-12345',
            dryRun: false,
        );

        // 5. Verify
        $this->assertFalse($result['dry_run']);
        $this->assertContains('users', $result['tables_planned']);
        $this->assertContains('app_settings', $result['tables_planned']);
        $this->assertGreaterThanOrEqual(1, $result['restored']['users']);

        $restored = User::query()->where('id', $userId)->first();
        $this->assertNotNull($restored);
        $this->assertEquals('restored', $restored->username);
        $this->assertEquals($userIdHash, $restored->password);

        $this->assertEquals('Vorher-Schule', AppSetting::singleton()->school_name);
    }

    #[Test]
    public function dry_run_does_not_modify_db(): void
    {
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        // Daten anlegen, die NICHT im Backup sind
        $newUser = User::create([
            'username' => 'after-backup', 'display_name' => 'X',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);

        $result = app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: true,
        );

        $this->assertTrue($result['dry_run']);
        $this->assertEmpty($result['restored']);
        // Nach-Backup-User ist noch da
        $this->assertNotNull(User::query()->find($newUser->id));
        // Kein Audit-Eintrag
        $this->assertEquals(0, AuditLog::query()->where('action', 'system.backup.restored')->count());
    }

    #[Test]
    public function restore_writes_audit_log_entry(): void
    {
        // Actor existiert vor dem Backup → ist auch nach Restore noch da (FK auf users.id)
        $actor = User::create([
            'username' => 'actor', 'display_name' => 'A',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: false,
            actorUserId: $actor->id,
        );

        $audit = AuditLog::query()->where('action', 'system.backup.restored')->first();
        $this->assertNotNull($audit);
        $this->assertEquals('user', $audit->actor_type);
        $this->assertEquals($actor->id, $audit->actor_user_id);
        $this->assertNotNull($audit->context['sha256']);
        $this->assertGreaterThan(0, $audit->context['tables_restored']);
    }

    #[Test]
    public function restore_skips_migrations_table(): void
    {
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        $result = app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: true,
        );

        $this->assertNotContains('migrations', $result['tables_planned']);
        $this->assertArrayHasKey('migrations', $result['tables_skipped']);
        $this->assertStringContainsString('Schema-Drift', $result['tables_skipped']['migrations']);
    }

    #[Test]
    public function wrong_password_aborts_with_runtime_exception(): void
    {
        $target = $this->makeBackupTarget('right-pw-12345');
        $run = app(BackupRunner::class)->run($target);

        // Einen User anlegen, der NICHT im Backup ist — wird nach Fehlversuch noch da sein
        $survivor = User::create([
            'username' => 'survivor', 'display_name' => 'S',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        try {
            app(BackupRestorer::class)->restore(
                absoluteFilePath: $this->backupAbsolutePath($run->file_name),
                password: 'wrong-pw-12345',
                dryRun: false,
            );
        } finally {
            // DB unverändert
            $this->assertNotNull(User::query()->find($survivor->id));
        }
    }

    #[Test]
    public function version_mismatch_aborts_unless_overridden(): void
    {
        config()->set('app.version', '1.0.0');
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        // App-Version ändern → Mismatch
        config()->set('app.version', '2.0.0');

        // Mit Default-Allow=false: Exception
        try {
            app(BackupRestorer::class)->restore(
                absoluteFilePath: $this->backupAbsolutePath($run->file_name),
                password: 'pw-12345',
                dryRun: true,
            );
            $this->fail('Erwartete RuntimeException für Version-Mismatch wurde nicht geworfen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('App-Version-Abweichung', $e->getMessage());
        }

        // Mit Override: läuft durch
        $result = app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: true,
            allowVersionMismatch: true,
        );
        $this->assertEquals('1.0.0', $result['manifest_version']);
    }

    #[Test]
    public function reports_schema_drift_for_columns(): void
    {
        // Mindestens ein User, damit das Backup nicht-leere users-Rows enthält
        User::create([
            'username' => 'sample', 'display_name' => 'S',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);

        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        // Manifest manipulieren: in der users-Tabelle eine fiktive Spalte 'old_field'
        // hinzufügen, die in der echten DB nicht existiert. Außerdem das Feld
        // 'username' aus dem Manifest entfernen — DB hat es, Backup nicht.
        $blob = file_get_contents($this->backupAbsolutePath($run->file_name));
        $payload = app(BackupRunner::class)->decrypt($blob, 'pw-12345');
        $manifest = json_decode($payload, true);
        if (! empty($manifest['tables']['users'])) {
            foreach ($manifest['tables']['users'] as &$row) {
                $row['old_field'] = 'legacy';
                unset($row['username']);
            }
            unset($row);
        }
        $modifiedBlob = app(BackupRunner::class)->encrypt(json_encode($manifest), 'pw-12345');
        file_put_contents($this->backupAbsolutePath($run->file_name), $modifiedBlob);

        $result = app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: true,
        );

        $this->assertArrayHasKey('users', $result['schema_drift']);
        $this->assertContains('old_field', $result['schema_drift']['users']['extra_in_backup']);
        $this->assertContains('username', $result['schema_drift']['users']['extra_in_db']);
    }

    #[Test]
    public function reports_no_drift_when_schemas_match(): void
    {
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        $result = app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: true,
        );

        $this->assertEmpty($result['schema_drift']);
    }

    #[Test]
    public function reports_extra_tables_in_db_not_in_backup(): void
    {
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        // Eine Tabelle aus dem Backup-Manifest entfernen, dann Plan ansehen
        $blob = file_get_contents($this->backupAbsolutePath($run->file_name));
        $payload = app(BackupRunner::class)->decrypt($blob, 'pw-12345');
        $manifest = json_decode($payload, true);
        unset($manifest['tables']['users']);
        $modifiedBlob = app(BackupRunner::class)->encrypt(json_encode($manifest), 'pw-12345');
        file_put_contents($this->backupAbsolutePath($run->file_name), $modifiedBlob);

        $result = app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: true,
        );

        $this->assertContains('users', $result['tables_extra_in_db']);
        $this->assertNotContains('users', $result['tables_planned']);
    }

    #[Test]
    public function command_dry_run_succeeds_without_modifying_db(): void
    {
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        $newUser = User::create([
            'username' => 'after', 'display_name' => 'X',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);

        $this->artisan('backup:restore', [
            'file' => $run->file_name,
            '--password' => 'pw-12345',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry-Run')
            ->assertSuccessful();

        $this->assertNotNull(User::query()->find($newUser->id));
    }

    #[Test]
    public function command_aborts_when_user_declines_confirmation(): void
    {
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        $newUser = User::create([
            'username' => 'after', 'display_name' => 'X',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);

        $this->artisan('backup:restore', [
            'file' => $run->file_name,
            '--password' => 'pw-12345',
        ])
            ->expectsConfirmation('Wirklich fortfahren?', 'no')
            ->expectsOutputToContain('Abgebrochen')
            ->assertFailed();

        // DB unverändert
        $this->assertNotNull(User::query()->find($newUser->id));
    }

    #[Test]
    public function snapshot_before_creates_local_snapshot_with_pre_restore_state(): void
    {
        // Pre-Restore-Daten anlegen
        $userBefore = User::create([
            'username' => 'pre-restore', 'display_name' => 'Vor-Restore',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);

        // Backup ohne diesen User
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        // User existiert vor Restore nicht im Backup, würde durch Restore weg sein.
        // Mit snapshotBefore=true wird vor TRUNCATE der Pre-Restore-Stand
        // (inklusive userBefore) lokal als NOENC-Snapshot abgelegt.
        $userBefore = User::create([
            'username' => 'survives-only-via-snapshot', 'display_name' => 'X',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);

        $result = app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: false,
            snapshotBefore: true,
        );

        $this->assertNotNull($result['pre_snapshot_path']);
        $this->assertStringStartsWith('lsp/backups/lsp_snapshot_pre_restore_', $result['pre_snapshot_path']);
        Storage::disk('local')->assertExists($result['pre_snapshot_path']);

        // Pre-Restore-User ist nach Restore weg (Backup hatte ihn nicht)
        $this->assertNull(User::query()->where('username', 'survives-only-via-snapshot')->first());

        // Aber im Snapshot ist er enthalten — das beweisen wir, indem wir den
        // Snapshot zurückspielen und prüfen, dass der User wieder da ist.
        $snapshotPath = Storage::disk('local')->path($result['pre_snapshot_path']);
        $restored = app(BackupRestorer::class)->restore(
            absoluteFilePath: $snapshotPath,
            password: '', // NOENC
            dryRun: false,
        );
        $this->assertNotNull(User::query()->where('username', 'survives-only-via-snapshot')->first());
    }

    #[Test]
    public function snapshot_before_path_appears_in_audit_context(): void
    {
        $actor = User::create([
            'username' => 'actor', 'display_name' => 'A',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: false,
            actorUserId: $actor->id,
            snapshotBefore: true,
        );

        $audit = AuditLog::query()
            ->where('action', 'system.backup.restored')->first();
        $this->assertNotNull($audit->context['pre_snapshot_path']);
        $this->assertStringStartsWith('lsp/backups/', $audit->context['pre_snapshot_path']);
    }

    #[Test]
    public function snapshot_before_command_flag_creates_snapshot(): void
    {
        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        $this->artisan('backup:restore', [
            'file' => $run->file_name,
            '--password' => 'pw-12345',
            '--force' => true,
            '--snapshot-before' => true,
        ])
            ->expectsOutputToContain('Pre-Restore-Snapshot: lsp/backups/lsp_snapshot_pre_restore_')
            ->assertSuccessful();
    }

    #[Test]
    public function backup_includes_storage_files_and_restore_writes_them_back(): void
    {
        // Datei in einem konfigurierten Backup-Pfad anlegen
        Storage::disk('local')->put('lsp/imports/sample.csv', "vorher;daten\n");

        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        // Datei nach Backup verändern und löschen
        Storage::disk('local')->put('lsp/imports/sample.csv', 'manipuliert');
        Storage::disk('local')->delete('lsp/imports/sample.csv');
        $this->assertFalse(Storage::disk('local')->exists('lsp/imports/sample.csv'));

        $result = app(BackupRestorer::class)->restore(
            absoluteFilePath: $this->backupAbsolutePath($run->file_name),
            password: 'pw-12345',
            dryRun: false,
        );

        $this->assertEquals(1, $result['files_restored']);
        $this->assertEquals(0, $result['files_skipped']);
        $this->assertTrue(Storage::disk('local')->exists('lsp/imports/sample.csv'));
        $this->assertEquals(
            "vorher;daten\n",
            Storage::disk('local')->get('lsp/imports/sample.csv'),
        );
    }

    #[Test]
    public function backup_excludes_backups_directory_itself(): void
    {
        // Sentinel: eine "Backup-Datei" anlegen, dann ein Backup ziehen
        Storage::disk('local')->put('lsp/backups/old-marker.bin', 'pseudo-backup');
        // Auch im inkludierten Pfad eine Datei, damit das Manifest sicher 'files' enthält
        Storage::disk('local')->put('lsp/imports/sentinel.csv', 'a;b');

        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        $blob = file_get_contents($this->backupAbsolutePath($run->file_name));
        $payload = app(BackupRunner::class)->decrypt($blob, 'pw-12345');
        $manifest = json_decode($payload, true);

        $this->assertNotEmpty($manifest['files']);
        $hasBackupPath = false;
        foreach (array_keys($manifest['files']) as $path) {
            if (str_contains($path, 'lsp/backups/')) {
                $hasBackupPath = true;
            }
        }
        $this->assertFalse($hasBackupPath, 'Backup-Verzeichnis darf nicht im Manifest sein.');
    }

    #[Test]
    public function backup_skips_files_larger_than_max_size(): void
    {
        config()->set('lsp.backup.max_file_size_bytes', 100); // 100 Bytes
        Storage::disk('local')->put('lsp/imports/big.bin', str_repeat('X', 500));
        Storage::disk('local')->put('lsp/imports/small.bin', 'klein');

        $target = $this->makeBackupTarget('pw-12345');
        $run = app(BackupRunner::class)->run($target);

        $blob = file_get_contents($this->backupAbsolutePath($run->file_name));
        $payload = app(BackupRunner::class)->decrypt($blob, 'pw-12345');
        $manifest = json_decode($payload, true);

        $this->assertNull($manifest['files']['lsp/imports/big.bin']['content_b64']);
        $this->assertEquals('datei_zu_gross', $manifest['files']['lsp/imports/big.bin']['skipped_reason']);
        $this->assertNotNull($manifest['files']['lsp/imports/small.bin']['content_b64']);
    }

    #[Test]
    public function command_force_skips_confirmation_and_restores(): void
    {
        $target = $this->makeBackupTarget('pw-12345');
        $userBefore = User::create([
            'username' => 'pre-backup', 'display_name' => 'P',
            'password' => Hash::make('pw'), 'is_active' => true,
        ]);
        $run = app(BackupRunner::class)->run($target);

        // User entfernen
        DB::table('users')->where('id', $userBefore->id)->delete();

        $this->artisan('backup:restore', [
            'file' => $run->file_name,
            '--password' => 'pw-12345',
            '--force' => true,
        ])
            ->expectsOutputToContain('Restore abgeschlossen')
            ->assertSuccessful();

        $this->assertNotNull(User::query()->find($userBefore->id));
    }
}
