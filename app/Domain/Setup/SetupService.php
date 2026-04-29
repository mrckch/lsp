<?php

declare(strict_types=1);

namespace App\Domain\Setup;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crypto\CryptoService;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SetupService
{
    public function __construct(
        private readonly CryptoService $crypto,
        private readonly AuditLogger $audit,
    ) {}

    public function isInitialized(): bool
    {
        return AppSetting::isInitialized();
    }

    /**
     * Vollständiger Setup-Vorgang.
     *
     * @return array{user: User, recovery_key: string}
     */
    public function initialize(
        string $adminUsername,
        string $adminDisplayName,
        ?string $adminEmail,
        string $adminPassword,
        string $schoolName,
        ?string $schoolShortName,
        string $clearnamePassword,
    ): array {
        if ($this->isInitialized()) {
            throw new \RuntimeException('LSP ist bereits initialisiert.');
        }

        return DB::transaction(function () use (
            $adminUsername, $adminDisplayName, $adminEmail, $adminPassword,
            $schoolName, $schoolShortName, $clearnamePassword,
        ) {
            $admin = User::create([
                'username' => $adminUsername,
                'display_name' => $adminDisplayName,
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'is_active' => true,
                'two_factor_enabled' => false,
                'password_changed_at' => now(),
            ]);

            $adminGroupId = DB::table('user_groups')->where('name', 'Admin')->value('id');
            if ($adminGroupId !== null) {
                DB::table('user_group_memberships')->insert([
                    'user_id' => $admin->id,
                    'user_group_id' => $adminGroupId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $settings = AppSetting::singleton();
            $settings->update([
                'school_name' => $schoolName,
                'school_short_name' => $schoolShortName,
                'is_initialized' => true,
                'initialized_at' => now(),
            ]);

            // DEK + Wraps + Recovery-Key (Recovery-Key wird einmalig zurückgegeben)
            $recoveryKey = $this->crypto->initialize($admin, $clearnamePassword);

            $this->audit->logUser(
                actor: $admin,
                action: 'system.setup.completed',
                entityType: 'app_settings',
                entityId: $settings->id,
                context: ['school_name' => $schoolName],
            );

            return [
                'user' => $admin,
                'recovery_key' => $recoveryKey,
            ];
        });
    }
}
