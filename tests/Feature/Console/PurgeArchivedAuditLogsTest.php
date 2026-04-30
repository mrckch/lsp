<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurgeArchivedAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    private function makeArchivedLog(string $action, \DateTimeInterface $archivedAt): AuditLog
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
        // Update archived_at
        AuditLog::query()->where('id', $log->id)->update(['archived_at' => $archivedAt]);

        return $log->refresh();
    }

    private function makeActiveLog(string $action): AuditLog
    {
        return AuditLog::create([
            'actor_type' => 'system',
            'actor_user_id' => null,
            'action' => $action,
            'entity_type' => null,
            'entity_id' => null,
            'context' => [],
            'includes_clearnames' => false,
        ]);
    }

    #[Test]
    public function it_does_nothing_when_no_logs_exceed_threshold(): void
    {
        $this->makeArchivedLog('a', now()->subDays(100));

        $this->artisan('audit:purge')
            ->expectsOutputToContain('Keine Einträge zum Purgen')
            ->assertSuccessful();

        $this->assertEquals(1, AuditLog::query()->count());
    }

    #[Test]
    public function it_purges_archived_logs_older_than_default_threshold(): void
    {
        // Default 730 Tage
        $old = $this->makeArchivedLog('old', now()->subDays(800));
        $recentArchived = $this->makeArchivedLog('recent', now()->subDays(100));
        $active = $this->makeActiveLog('still_active');

        $this->artisan('audit:purge')->assertSuccessful();

        $this->assertNull(AuditLog::query()->find($old->id));
        $this->assertNotNull(AuditLog::query()->find($recentArchived->id));
        $this->assertNotNull(AuditLog::query()->find($active->id));
    }

    #[Test]
    public function it_does_not_touch_non_archived_entries(): void
    {
        // Sehr alter Eintrag, aber NIE archiviert → bleibt erhalten
        $old = $this->makeActiveLog('old_active');
        AuditLog::query()->where('id', $old->id)->update(['created_at' => now()->subDays(2000)]);

        $this->artisan('audit:purge')->assertSuccessful();

        $this->assertNotNull(AuditLog::query()->find($old->id));
    }

    #[Test]
    public function dry_run_does_not_purge(): void
    {
        $log = $this->makeArchivedLog('alt', now()->subDays(800));

        $this->artisan('audit:purge --dry-run')
            ->expectsOutputToContain('Dry-Run')
            ->assertSuccessful();

        $this->assertNotNull(AuditLog::query()->find($log->id));
    }

    #[Test]
    public function it_writes_own_audit_entry(): void
    {
        $this->makeArchivedLog('alt', now()->subDays(800));

        $this->artisan('audit:purge')->assertSuccessful();

        $audit = AuditLog::query()->where('action', 'audit.purge')->first();
        $this->assertNotNull($audit);
        $this->assertEquals('system', $audit->actor_type);
        $this->assertEquals(1, $audit->context['purged']);
        $this->assertEquals(730, $audit->context['cutoff_days']);
    }

    #[Test]
    public function custom_days_option_respected(): void
    {
        $log10 = $this->makeArchivedLog('ten', now()->subDays(10));
        $log20 = $this->makeArchivedLog('twenty', now()->subDays(20));

        $this->artisan('audit:purge --days=15')->assertSuccessful();

        $this->assertNotNull(AuditLog::query()->find($log10->id));
        $this->assertNull(AuditLog::query()->find($log20->id));
    }

    #[Test]
    public function rejects_invalid_days_value(): void
    {
        $this->artisan('audit:purge --days=0')
            ->expectsOutputToContain('mindestens 1')
            ->assertFailed();
    }
}
