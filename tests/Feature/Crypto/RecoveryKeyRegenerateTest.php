<?php

declare(strict_types=1);

namespace Tests\Feature\Crypto;

use App\Domain\Crypto\CryptoService;
use App\Domain\Crypto\Exceptions\CryptoException;
use App\Domain\Crypto\Models\KeyWrap;
use App\Domain\Crypto\Models\RecoveryKey;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecoveryKeyRegenerateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CryptoService $crypto;

    private string $initialRecoveryKey;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::singleton()->update(['is_initialized' => true]);

        $this->admin = User::create([
            'username' => 'admin', 'display_name' => 'A',
            'password' => Hash::make('admin-pw-1234567890'), 'is_active' => true,
        ]);
        $this->crypto = app(CryptoService::class);
        $this->initialRecoveryKey = $this->crypto->initialize($this->admin, 'clear-pw-1234567890');
    }

    #[Test]
    public function regenerate_returns_new_key_different_from_old(): void
    {
        $newKey = $this->crypto->regenerateRecoveryKey();

        $this->assertNotEquals($this->initialRecoveryKey, $newKey);
        // Recovery-Keys haben Format: 4er-Gruppen mit Bindestrich, ~52+ Zeichen
        $this->assertMatchesRegularExpression('/^[A-Z2-9-]+$/', $newKey);
    }

    #[Test]
    public function regenerate_invalidates_old_recovery_key(): void
    {
        $this->crypto->regenerateRecoveryKey();

        // Alter Key kann DEK nicht mehr entwrappen → recoverWithKey gibt false
        $crypto = app(CryptoService::class);
        $this->assertFalse(
            $crypto->recoverWithKey($this->admin, $this->initialRecoveryKey, 'new-pw-1234567890'),
        );
    }

    #[Test]
    public function regenerated_key_can_recover(): void
    {
        $newKey = $this->crypto->regenerateRecoveryKey();

        // Klartext mit Session-DEK verschlüsseln
        $blob = $this->crypto->encryptWithSessionDek('Geheim');
        $this->crypto->lock();

        // Recover mit neuem Key + neues Passwort
        $this->assertTrue(
            $this->crypto->recoverWithKey($this->admin, $newKey, 'recovered-pw-1234567890'),
        );
        // Nach Recover: User mit neuem PW entsperren, Klartext lesen
        $this->crypto->lock();
        $this->assertTrue($this->crypto->unlock($this->admin, 'recovered-pw-1234567890'));
        $this->assertEquals('Geheim', $this->crypto->decryptWithSessionDek($blob));
    }

    #[Test]
    public function regenerate_marks_old_recovery_key_entries_as_revoked(): void
    {
        $countBefore = RecoveryKey::query()->whereNull('revoked_at')->count();
        $this->assertEquals(1, $countBefore);

        $this->crypto->regenerateRecoveryKey();

        // 1 alter (revoked) + 1 neuer (active) = 2 Einträge
        $this->assertEquals(2, RecoveryKey::query()->count());
        $this->assertEquals(1, RecoveryKey::query()->whereNotNull('revoked_at')->count());
        $this->assertEquals(1, RecoveryKey::query()->whereNull('revoked_at')->count());
    }

    #[Test]
    public function regenerate_replaces_recovery_wrap_in_key_wraps(): void
    {
        $oldWrap = KeyWrap::query()->where('wrap_type', 'recovery_key')->first();
        $oldId = $oldWrap->id;

        $this->crypto->regenerateRecoveryKey();

        // Alter Wrap weg, neuer da
        $this->assertNull(KeyWrap::query()->find($oldId));
        $this->assertEquals(1, KeyWrap::query()->where('wrap_type', 'recovery_key')->count());
    }

    #[Test]
    public function regenerate_throws_when_session_locked(): void
    {
        $this->crypto->lock();

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('nicht entsperrt');
        $this->crypto->regenerateRecoveryKey();
    }

    #[Test]
    public function regenerate_uses_custom_label(): void
    {
        $this->crypto->regenerateRecoveryKey('Manuelle-Erneuerung-2026');

        $latest = RecoveryKey::query()
            ->whereNull('revoked_at')
            ->whereNull('used_at')
            ->latest('id')->first();
        $this->assertEquals('Manuelle-Erneuerung-2026', $latest->label);
    }
}
