<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Permission\Models\UserGroup;
use App\Domain\Permission\PermissionResolver;
use App\Filament\Widgets\AuditStats;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditStatsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true]);
        $this->app->singleton(PermissionResolver::class, fn () => new PermissionResolver(useCache: false));

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);

        $this->teacher = User::create([
            'username' => 't', 'display_name' => 'T',
            'password' => Hash::make('teacher-pw-1234567890'), 'is_active' => true,
        ]);
        $this->teacher->userGroups()->attach(UserGroup::where('name', 'Lehrkraft')->first()->id);
    }

    private function audit(string $action, bool $clearnames = false, ?\DateTimeInterface $when = null): void
    {
        $a = AuditLog::create([
            'actor_type' => 'user',
            'actor_user_id' => $this->admin->id,
            'action' => $action,
            'includes_clearnames' => $clearnames,
            'context' => [],
        ]);
        if ($when !== null) {
            $a->forceFill(['created_at' => $when])->save();
        }
    }

    private function callStats(): array
    {
        $widget = new AuditStats;
        $ref = new \ReflectionMethod($widget, 'getStats');
        $ref->setAccessible(true);

        return $ref->invoke($widget);
    }

    #[Test]
    public function widget_visible_only_with_audit_view_permission(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue(AuditStats::canView());

        $this->actingAs($this->teacher);
        $this->assertFalse(AuditStats::canView());
    }

    #[Test]
    public function counts_today_and_seven_days(): void
    {
        $this->actingAs($this->admin);

        $this->audit('clearname.unlock', when: now()->subHours(1));
        $this->audit('clearname.unlock', when: now()->subDays(2));
        $this->audit('clearname.unlock', when: now()->subDays(10)); // außerhalb 7 Tage

        $stats = $this->callStats();
        $unlockStat = $stats[0];

        $this->assertEquals('Klarnamen entsperrt', $unlockStat->getLabel());
        $this->assertEquals('1', $unlockStat->getValue());
        $this->assertStringContainsString('2', $unlockStat->getDescription());
    }

    #[Test]
    public function clearname_action_count_uses_includes_clearnames_flag(): void
    {
        $this->actingAs($this->admin);

        $this->audit('attempts.export_with_clearname', clearnames: true, when: now());
        $this->audit('print.generate_with_clearname', clearnames: true, when: now()->subDays(3));
        $this->audit('students.view', clearnames: false, when: now()); // soll NICHT zählen

        $stats = $this->callStats();
        $clearnameStat = $stats[1];
        $this->assertEquals('1', $clearnameStat->getValue()); // heute 1
        $this->assertStringContainsString('2', $clearnameStat->getDescription()); // 7d 2
    }

    #[Test]
    public function deletion_count_combines_delete_and_archive(): void
    {
        $this->actingAs($this->admin);

        $this->audit('students.delete', when: now());
        $this->audit('students.archive', when: now()->subDay());

        $stats = $this->callStats();
        $delStat = $stats[2];
        $this->assertEquals('1', $delStat->getValue());
        $this->assertStringContainsString('2', $delStat->getDescription());
    }

    #[Test]
    public function job_failures_count(): void
    {
        $this->actingAs($this->admin);

        $this->audit('job.failed', when: now());
        $this->audit('job.failed', when: now()->subDays(5));

        $stats = $this->callStats();
        $failStat = $stats[3];
        $this->assertEquals('1', $failStat->getValue());
        $this->assertStringContainsString('2', $failStat->getDescription());
    }
}
