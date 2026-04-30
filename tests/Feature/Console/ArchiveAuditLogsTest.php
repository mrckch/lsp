<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArchiveAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    private function makeLog(string $action, \DateTimeInterface $createdAt): AuditLog
    {
        $log = AuditLog::create([
            'actor_type' => 'system',
            'actor_user_id' => null,
            'action' => $action,
            'entity_type' => null,
            'entity_id' => null,
            'context' => [],
            'includes_clearnames' => false,
        ]);
        // created_at via UseCurrent gesetzt, hier explizit überschreiben für Tests
        AuditLog::query()->where('id', $log->id)->update(['created_at' => $createdAt]);

        return $log->refresh();
    }

    #[Test]
    public function it_does_nothing_when_no_logs_exceed_threshold(): void
    {
        $this->makeLog('a', now()->subDays(10));

        $this->artisan('audit:archive')
            ->expectsOutputToContain('Keine Einträge zum Archivieren')
            ->assertSuccessful();

        $this->assertNull(AuditLog::query()->where('action', 'a')->first()->archived_at);
    }

    #[Test]
    public function it_archives_logs_older_than_default_threshold(): void
    {
        // Default: 90 Tage
        $old = $this->makeLog('old', now()->subDays(91));
        $recent = $this->makeLog('recent', now()->subDays(89));

        $this->artisan('audit:archive')->assertSuccessful();

        $this->assertNotNull($old->refresh()->archived_at);
        $this->assertNull($recent->refresh()->archived_at);
    }

    #[Test]
    public function it_uses_custom_days_option(): void
    {
        $log10 = $this->makeLog('ten', now()->subDays(10));
        $log20 = $this->makeLog('twenty', now()->subDays(20));

        $this->artisan('audit:archive --days=15')->assertSuccessful();

        $this->assertNull($log10->refresh()->archived_at);
        $this->assertNotNull($log20->refresh()->archived_at);
    }

    #[Test]
    public function dry_run_does_not_archive(): void
    {
        $log = $this->makeLog('a', now()->subDays(100));

        $this->artisan('audit:archive --dry-run')
            ->expectsOutputToContain('Dry-Run')
            ->assertSuccessful();

        $this->assertNull($log->refresh()->archived_at);
    }

    #[Test]
    public function it_writes_own_audit_entry(): void
    {
        $this->makeLog('alt', now()->subDays(100));

        $this->artisan('audit:archive')->assertSuccessful();

        $audit = AuditLog::query()->where('action', 'audit.archive')->first();
        $this->assertNotNull($audit);
        $this->assertEquals('system', $audit->actor_type);
        $this->assertEquals(1, $audit->context['archived']);
        $this->assertEquals(90, $audit->context['cutoff_days']);
    }

    #[Test]
    public function rejects_invalid_days_value(): void
    {
        $this->artisan('audit:archive --days=0')
            ->expectsOutputToContain('mindestens 1')
            ->assertFailed();
    }

    #[Test]
    public function active_scope_excludes_archived(): void
    {
        $this->makeLog('aktiv', now()->subDay());
        $this->makeLog('zu_archivieren', now()->subDays(100));
        $this->artisan('audit:archive')->assertSuccessful();

        // active scope sollte nur 1 nicht-archivierten + 1 vom Cron selbst zeigen
        $activeCount = AuditLog::query()->active()->count();
        $archivedCount = AuditLog::query()->archived()->count();
        $this->assertEquals(2, $activeCount); // 'aktiv' + 'audit.archive'-Eintrag
        $this->assertEquals(1, $archivedCount);
    }
}
