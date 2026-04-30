<?php

declare(strict_types=1);

namespace Tests\Feature\Crypto;

use App\Domain\Crypto\CryptoService;
use App\Domain\Crypto\Exceptions\CryptoException;
use App\Domain\Crypto\Models\KeyWrap;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WrapProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private CryptoService $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['is_initialized' => true]);

        $this->admin = User::create([
            'username' => 'admin', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->teacher = User::create([
            'username' => 'lehrer', 'display_name' => 'L',
            'password' => Hash::make('lehrer-pw-1234567890'), 'is_active' => true,
        ]);

        $this->crypto = app(CryptoService::class);
        $this->crypto->initialize($this->admin, 'clearname-pw-1234567890');
        // Admin-Session ist nun entsperrt (DEK liegt in Session)
    }

    #[Test]
    public function provision_creates_wrap_for_other_user(): void
    {
        $this->assertFalse($this->crypto->hasActiveWrap($this->teacher));

        $wrap = $this->crypto->provisionWrapForUser($this->teacher, 'init-pw-1234567890');

        $this->assertTrue($this->crypto->hasActiveWrap($this->teacher));
        $this->assertEquals($this->teacher->id, $wrap->user_id);
        $this->assertNotNull($wrap->rotation_required_at);
    }

    #[Test]
    public function teacher_can_unlock_with_provisioned_password(): void
    {
        $this->crypto->provisionWrapForUser($this->teacher, 'init-pw-1234567890');
        $this->crypto->lock();

        $this->assertTrue($this->crypto->unlock($this->teacher, 'init-pw-1234567890'));
    }

    #[Test]
    public function provision_fails_when_session_not_unlocked(): void
    {
        $this->crypto->lock();

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('nicht entsperrt');

        $this->crypto->provisionWrapForUser($this->teacher, 'init-pw-1234567890');
    }

    #[Test]
    public function provision_fails_when_user_already_has_wrap(): void
    {
        $this->crypto->provisionWrapForUser($this->teacher, 'init-pw-1234567890');

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('bereits ein Klarnamen-Wrap');

        $this->crypto->provisionWrapForUser($this->teacher, 'another-pw-1234567890');
    }

    #[Test]
    public function provisioned_wrap_decrypts_same_data_as_admin_wrap(): void
    {
        // Admin verschlüsselt einen Klartext mit seiner Session-DEK
        $blob = $this->crypto->encryptWithSessionDek('Hallo Welt');
        $this->crypto->provisionWrapForUser($this->teacher, 'init-pw-1234567890');

        // Lehrer entsperrt mit seinem Initial-Passwort und entschlüsselt denselben Blob
        $this->crypto->lock();
        $this->assertTrue($this->crypto->unlock($this->teacher, 'init-pw-1234567890'));
        $this->assertEquals('Hallo Welt', $this->crypto->decryptWithSessionDek($blob));
    }

    #[Test]
    public function revoke_removes_user_wrap(): void
    {
        $this->crypto->provisionWrapForUser($this->teacher, 'init-pw-1234567890');
        $this->assertTrue($this->crypto->hasActiveWrap($this->teacher));

        $count = $this->crypto->revokeWrapForUser($this->teacher);

        $this->assertEquals(1, $count);
        $this->assertFalse($this->crypto->hasActiveWrap($this->teacher));
        // Admin-Wrap unangetastet
        $this->assertTrue($this->crypto->hasActiveWrap($this->admin));
    }

    #[Test]
    public function revoke_returns_zero_when_no_wrap(): void
    {
        $this->assertEquals(0, $this->crypto->revokeWrapForUser($this->teacher));
    }

    #[Test]
    public function revoked_user_cannot_unlock(): void
    {
        $this->crypto->provisionWrapForUser($this->teacher, 'init-pw-1234567890');
        $this->crypto->revokeWrapForUser($this->teacher);
        $this->crypto->lock();

        $this->assertFalse($this->crypto->unlock($this->teacher, 'init-pw-1234567890'));
    }

    #[Test]
    public function revoke_does_not_affect_recovery_wrap(): void
    {
        $this->crypto->revokeWrapForUser($this->admin);
        // Recovery-Wrap (wrap_type='recovery_key') bleibt erhalten
        $this->assertEquals(
            1,
            KeyWrap::query()->where('wrap_type', 'recovery_key')->count(),
        );
    }
}
