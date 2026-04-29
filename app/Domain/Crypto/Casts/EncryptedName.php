<?php

declare(strict_types=1);

namespace App\Domain\Crypto\Casts;

use App\Domain\Crypto\CryptoService;
use App\Domain\Crypto\Exceptions\CryptoException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent-Cast für klarnamenverschlüsselte Felder (Vor-/Nachname).
 *
 * Beim Lesen: liefert Klartext, wenn Klarnamen-Session entsperrt; sonst '***'.
 * Beim Schreiben: erfordert entsperrte Klarnamen-Session, sonst Exception.
 */
class EncryptedName implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $crypto = app(CryptoService::class);
        if (! $crypto->isUnlocked()) {
            return '***';
        }

        try {
            return $crypto->decryptWithSessionDek(is_resource($value) ? stream_get_contents($value) : $value);
        } catch (CryptoException) {
            return '***';
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $crypto = app(CryptoService::class);
        if (! $crypto->isUnlocked()) {
            throw new CryptoException(
                "Klarnamen-Session ist gesperrt – Schreiben des verschlüsselten Feldes '$key' nicht möglich.",
            );
        }

        return [$key => $crypto->encryptWithSessionDek((string) $value)];
    }
}
