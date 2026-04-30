<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Mail\Models\MailMessage;
use App\Domain\PrintJob\Models\GeneratedDocument;
use App\Filament\Widgets\MyJobsStatus;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\DefaultUserGroupsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MyJobsStatusWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionCatalogSeeder::class, DefaultUserGroupsSeeder::class]);
        AppSetting::singleton()->update(['is_initialized' => true]);

        $this->admin = User::create([
            'username' => 'a', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->other = User::create([
            'username' => 'o', 'display_name' => 'O',
            'password' => Hash::make('other-pw-1234567890'), 'is_active' => true,
        ]);
    }

    private function widget(): MyJobsStatus
    {
        return new MyJobsStatus;
    }

    #[Test]
    public function activities_empty_when_no_records(): void
    {
        $this->actingAs($this->admin);
        $this->assertCount(0, $this->widget()->getActivities());
    }

    #[Test]
    public function activities_show_users_documents_only(): void
    {
        // Ein Doc vom Admin, eins vom anderen User
        GeneratedDocument::create([
            'file_name' => 'admin.zip', 'file_path' => '/x/admin.zip',
            'mime_type' => 'application/zip', 'size_bytes' => 1024,
            'includes_clearnames' => true, 'sha256' => str_repeat('a', 64),
            'created_by_user_id' => $this->admin->id,
        ]);
        GeneratedDocument::create([
            'file_name' => 'other.zip', 'file_path' => '/x/other.zip',
            'mime_type' => 'application/zip', 'size_bytes' => 2048,
            'includes_clearnames' => false, 'sha256' => str_repeat('b', 64),
            'created_by_user_id' => $this->other->id,
        ]);

        $this->actingAs($this->admin);
        $rows = $this->widget()->getActivities();

        $this->assertCount(1, $rows);
        $this->assertEquals('admin.zip', $rows->first()['title']);
        $this->assertEquals('document', $rows->first()['kind']);
        $this->assertTrue($rows->first()['includes_clearnames']);
    }

    #[Test]
    public function activities_show_users_mails_with_status_and_recipient(): void
    {
        MailMessage::create([
            'to_addresses' => json_encode(['a@b.de', 'c@d.de']),
            'subject' => 'Hallo', 'status' => 'sent',
            'sent_by_user_id' => $this->admin->id, 'created_at' => now(),
            'includes_clearnames' => true,
        ]);

        $this->actingAs($this->admin);
        $rows = $this->widget()->getActivities();

        $this->assertCount(1, $rows);
        $this->assertEquals('mail', $rows->first()['kind']);
        $this->assertEquals('sent', $rows->first()['status']);
        $this->assertStringContainsString('a@b.de', $rows->first()['subtitle']);
    }

    #[Test]
    public function activities_are_combined_and_sorted_chronologically_desc(): void
    {
        $this->actingAs($this->admin);

        // Älteres Doc – created_at ist nicht fillable, daher forceFill
        $oldDoc = GeneratedDocument::create([
            'file_name' => 'older.pdf', 'file_path' => '/x/older.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100,
            'includes_clearnames' => false, 'sha256' => str_repeat('a', 64),
            'created_by_user_id' => $this->admin->id,
        ]);
        $oldDoc->forceFill(['created_at' => now()->subHours(2)])->save();
        // Neuere Mail
        MailMessage::create([
            'to_addresses' => json_encode(['x@y.de']),
            'subject' => 'Neuer', 'status' => 'sent',
            'sent_by_user_id' => $this->admin->id, 'created_at' => now()->subMinutes(10),
            'includes_clearnames' => false,
        ]);

        $rows = $this->widget()->getActivities()->all();
        $this->assertEquals('Neuer', $rows[0]['title']);
        $this->assertEquals('older.pdf', $rows[1]['title']);
    }

    #[Test]
    public function widget_visibility_requires_authentication(): void
    {
        $this->assertFalse(MyJobsStatus::canView());
        $this->actingAs($this->admin);
        $this->assertTrue(MyJobsStatus::canView());
    }
}
