<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Backup\Models\BackupTarget;
use App\Domain\Permission\Models\UserGroup;
use App\Filament\Resources\BackupTargetResource\Pages\CreateBackupTarget;
use App\Filament\Resources\BackupTargetResource\Pages\EditBackupTarget;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackupTargetResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true, 'school_name' => 'TestSchule']);

        $admin = User::create([
            'username' => 'admin', 'display_name' => 'Admin',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $admin->userGroups()->attach(UserGroup::where('name', 'Admin')->first()->id);
        $this->actingAs($admin);
    }

    #[Test]
    public function create_stores_backup_password_and_encrypts_sftp_credentials(): void
    {
        Livewire::test(CreateBackupTarget::class)
            ->fillForm([
                'name' => 'NAS',
                'type' => 'sftp',
                'encryption_password' => 'backup-pw-12345',
                'config_encrypted' => [
                    'host' => 'nas.schule.local',
                    'port' => 22,
                    'username' => 'lsp',
                    'password' => 'sftp-geheim',
                    'root' => '/backups',
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $target = BackupTarget::query()->firstOrFail();
        $this->assertSame('backup-pw-12345', $target->encryption_password);
        $this->assertSame('nas.schule.local', $target->config('host'));
        $this->assertSame('sftp-geheim', $target->config('password'));

        $raw = (string) DB::table('backup_targets')->value('config_encrypted');
        $this->assertStringNotContainsString('sftp-geheim', $raw);
        $this->assertStringNotContainsString('nas.schule.local', $raw);
    }

    #[Test]
    public function create_requires_backup_password(): void
    {
        Livewire::test(CreateBackupTarget::class)
            ->fillForm(['name' => 'Lokal', 'type' => 'local'])
            ->call('create')
            ->assertHasFormErrors(['encryption_password' => 'required']);
    }

    #[Test]
    public function edit_with_blank_secret_fields_keeps_existing_values(): void
    {
        $target = BackupTarget::create([
            'name' => 'NAS', 'type' => 'sftp', 'is_active' => true,
            'config_encrypted' => ['host' => 'nas', 'port' => 22, 'username' => 'lsp', 'password' => 'sftp-geheim'],
            'retention_daily' => 7, 'retention_weekly' => 4, 'retention_monthly' => 12,
        ]);
        $target->encryption_password = 'backup-pw-12345';
        $target->save();

        Livewire::test(EditBackupTarget::class, ['record' => $target->getRouteKey()])
            ->assertFormSet(['config_encrypted.password' => null, 'encryption_password' => null])
            ->fillForm(['name' => 'NAS neu'])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();
        $this->assertSame('NAS neu', $target->name);
        $this->assertSame('backup-pw-12345', $target->encryption_password);
        $this->assertSame('sftp-geheim', $target->config('password'));
        $this->assertSame('nas', $target->config('host'));
    }
}
