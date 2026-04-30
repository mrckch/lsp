<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Anlage neuer Benutzerkonten mit zufälligem Initial-Passwort und
 * erzwungenem Passwortwechsel beim ersten Login.
 */
final class OnboardingService
{
    /**
     * Erzeugt ein lesbares Initial-Passwort (16 Zeichen, ohne Verwechslungsgefahr).
     */
    public function generateInitialPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return rtrim(chunk_split($password, 4, '-'), '-');
    }

    /**
     * Markiert den User für „muss Passwort beim nächsten Login wechseln".
     */
    public function markForForcedChange(User $user, string $hashedPassword): User
    {
        $user->forceFill([
            'password' => $hashedPassword,
            'must_change_password' => true,
            'password_changed_at' => null,
        ])->save();

        return $user;
    }

    /**
     * Setzt das neue Passwort und entfernt die Pflicht-Flagge.
     */
    public function applyChosenPassword(User $user, string $plainPassword): void
    {
        $user->forceFill([
            'password' => Hash::make($plainPassword),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
    }
}
