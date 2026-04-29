<?php

declare(strict_types=1);

namespace App\Domain\Crypto;

use App\Domain\Crypto\Exceptions\CryptoException;
use App\Domain\Crypto\Models\EncryptionKey;
use App\Domain\Crypto\Models\KeyWrap;
use App\Domain\Crypto\Models\RecoveryKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Envelope-Encryption-Service.
 *
 * – Eine schulweite DEK (Data Encryption Key, 32 Bytes, AES-256-GCM).
 * – DEK wird nie im Klartext gespeichert; sie existiert nur in Memory einer Session,
 *   nachdem ein User sie via sein Klarnamen-Passwort entwrappt hat.
 * – DEK wird n-fach gewrapped: pro berechtigtem User-Passwort + 1× Recovery-Key.
 * – KEK = Argon2id(password, salt, params).
 * – Wrap = AES-256-GCM(plaintext=DEK, key=KEK, nonce=random_12).
 *
 * Session-Key: 'lsp.dek' enthält die rohe DEK (binär, hex-encoded für Session-Storage-Sicherheit).
 */
final class CryptoService
{
    public const SESSION_KEY = 'lsp.dek';

    public const DEK_BYTES = 32;          // AES-256
    public const NONCE_BYTES = 12;        // GCM Standard
    public const SALT_BYTES = 16;
    public const RECOVERY_KEY_BYTES = 32;

    public const DEFAULT_KDF = [
        'memory_cost' => 65536,           // 64 MiB
        'time_cost' => 4,
        'threads' => 1,
    ];

    /**
     * Erstinitialisierung – wird vom Setup-Wizard aufgerufen.
     *
     * Erzeugt:
     *  - eine neue DEK
     *  - einen Wrap mit dem Admin-Klarnamen-Passwort
     *  - einen Recovery-Key (wird ZURÜCKGEGEBEN, nirgends gespeichert)
     *
     * @return string Recovery-Key im Klartext (Base32, lesbar). NUR EINMALIG zurückgegeben.
     */
    public function initialize(User $admin, string $clearnamePassword): string
    {
        if (EncryptionKey::query()->where('is_active', true)->exists()) {
            throw new CryptoException('Crypto ist bereits initialisiert.');
        }

        $dek = random_bytes(self::DEK_BYTES);

        $encryptionKey = DB::transaction(function () use ($admin, $clearnamePassword, $dek) {
            /** @var EncryptionKey $key */
            $key = EncryptionKey::create([
                'key_version' => 1,
                'is_active' => true,
            ]);

            $this->createWrapForUser($key, $admin, $clearnamePassword, $dek);

            return $key;
        });

        $recoveryKey = $this->createRecoveryWrap($encryptionKey, $dek);

        // DEK direkt in die Admin-Session legen, damit er ohne Re-Login weiterarbeiten kann
        $this->putDekInSession($dek);

        // Sicherheit: DEK aus Memory entfernen (so gut PHP es zulässt)
        \sodium_memzero($dek);

        return $recoveryKey;
    }

    /**
     * User entsperrt die Klarnamen für seine Session.
     *
     * @return bool true bei Erfolg
     */
    public function unlock(User $user, string $password): bool
    {
        $wrap = $this->activeWrapForUser($user);

        if ($wrap === null) {
            return false;
        }

        try {
            $dek = $this->unwrap($wrap, $password);
        } catch (CryptoException) {
            return false;
        }

        $this->putDekInSession($dek);
        \sodium_memzero($dek);

        return true;
    }

    public function lock(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    public function isUnlocked(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    /**
     * Liefert die DEK aus der Session (Klartext-Bytes), oder wirft.
     */
    public function dekFromSession(): string
    {
        $hex = Session::get(self::SESSION_KEY);
        if (! is_string($hex) || $hex === '') {
            throw new CryptoException('Klarnamen-Session ist nicht entsperrt.');
        }

        return \sodium_hex2bin($hex);
    }

    /**
     * Verschlüsselt einen Klartext mit der Session-DEK (AES-256-GCM).
     */
    public function encryptWithSessionDek(string $plaintext): string
    {
        $dek = $this->dekFromSession();
        $nonce = random_bytes(self::NONCE_BYTES);
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $dek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($cipher === false) {
            throw new CryptoException('Verschlüsselung fehlgeschlagen.');
        }

        // Format: nonce || tag || cipher  → Base64 für DB-Storage
        return $nonce.$tag.$cipher;
    }

    public function decryptWithSessionDek(string $blob): string
    {
        $dek = $this->dekFromSession();
        $nonce = substr($blob, 0, self::NONCE_BYTES);
        $tag = substr($blob, self::NONCE_BYTES, 16);
        $cipher = substr($blob, self::NONCE_BYTES + 16);

        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $dek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plain === false) {
            throw new CryptoException('Entschlüsselung fehlgeschlagen.');
        }

        return $plain;
    }

    /**
     * User wechselt sein Klarnamen-Passwort.
     */
    public function changeUserPassword(User $user, string $oldPassword, string $newPassword): bool
    {
        $wrap = $this->activeWrapForUser($user);
        if ($wrap === null) {
            return false;
        }

        try {
            $dek = $this->unwrap($wrap, $oldPassword);
        } catch (CryptoException) {
            return false;
        }

        $encryptionKey = $wrap->encryptionKey;
        $wrap->delete();
        $this->createWrapForUser($encryptionKey, $user, $newPassword, $dek);

        \sodium_memzero($dek);

        return true;
    }

    /**
     * Recovery-Workflow: User vergisst sein Passwort, hat aber Recovery-Key.
     * → Erzeuge neuen Wrap für diesen User mit dem neuen Passwort.
     */
    public function recoverWithKey(User $user, string $recoveryKey, string $newPassword): bool
    {
        $activeKey = EncryptionKey::query()->where('is_active', true)->first();
        if ($activeKey === null) {
            throw new CryptoException('Keine aktive DEK gefunden.');
        }

        $recoveryWrap = KeyWrap::query()
            ->where('encryption_key_id', $activeKey->id)
            ->where('wrap_type', 'recovery_key')
            ->first();

        if ($recoveryWrap === null) {
            throw new CryptoException('Kein Recovery-Wrap vorhanden.');
        }

        try {
            $dek = $this->unwrap($recoveryWrap, $recoveryKey);
        } catch (CryptoException) {
            return false;
        }

        // Lösche eventuelles altes User-Wrap und lege neues an
        KeyWrap::query()
            ->where('encryption_key_id', $activeKey->id)
            ->where('wrap_type', 'user_password')
            ->where('user_id', $user->id)
            ->delete();

        $this->createWrapForUser($activeKey, $user, $newPassword, $dek);

        // Recovery-Key-Eintrag als 'used' markieren
        RecoveryKey::query()
            ->where('encryption_key_id', $activeKey->id)
            ->update(['used_at' => now()]);

        \sodium_memzero($dek);

        return true;
    }

    /**
     * Erzwingt für alle User-Wraps der aktiven DEK eine Rotation
     * (User muss beim nächsten Login ein neues Klarnamen-Passwort vergeben).
     */
    public function forceRotation(): int
    {
        $activeKey = EncryptionKey::query()->where('is_active', true)->first();
        if ($activeKey === null) {
            return 0;
        }

        return KeyWrap::query()
            ->where('encryption_key_id', $activeKey->id)
            ->where('wrap_type', 'user_password')
            ->update(['rotation_required_at' => now()]);
    }

    /**
     * Hat User eine aktive Wrap-Kopie?
     */
    public function hasActiveWrap(User $user): bool
    {
        return $this->activeWrapForUser($user) !== null;
    }

    public function rotationRequired(User $user): bool
    {
        $wrap = $this->activeWrapForUser($user);

        return $wrap !== null && $wrap->rotation_required_at !== null;
    }

    // ── private ──────────────────────────────────────────────────────────────

    private function createWrapForUser(EncryptionKey $key, User $user, string $password, string $dek): KeyWrap
    {
        $salt = random_bytes(self::SALT_BYTES);
        $kdfParams = self::DEFAULT_KDF;
        $kek = $this->deriveKek($password, $salt, $kdfParams);
        $nonce = random_bytes(self::NONCE_BYTES);

        $wrappedDek = openssl_encrypt(
            $dek,
            'aes-256-gcm',
            $kek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($wrappedDek === false) {
            throw new CryptoException('Wrap fehlgeschlagen.');
        }

        \sodium_memzero($kek);

        return KeyWrap::create([
            'encryption_key_id' => $key->id,
            'wrap_type' => 'user_password',
            'user_id' => $user->id,
            'wrapped_dek' => $wrappedDek,
            'wrap_nonce' => $nonce,
            'wrap_tag' => $tag,
            'kdf_salt' => $salt,
            'kdf_params' => $kdfParams,
            'verification_hash' => hash('sha256', $dek),
        ]);
    }

    private function createRecoveryWrap(EncryptionKey $key, string $dek): string
    {
        $recoveryKey = $this->generateRecoveryKey();

        $salt = random_bytes(self::SALT_BYTES);
        $kdfParams = self::DEFAULT_KDF;
        $kek = $this->deriveKek($recoveryKey, $salt, $kdfParams);
        $nonce = random_bytes(self::NONCE_BYTES);

        $wrappedDek = openssl_encrypt(
            $dek,
            'aes-256-gcm',
            $kek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($wrappedDek === false) {
            throw new CryptoException('Recovery-Wrap fehlgeschlagen.');
        }

        \sodium_memzero($kek);

        KeyWrap::create([
            'encryption_key_id' => $key->id,
            'wrap_type' => 'recovery_key',
            'user_id' => null,
            'wrapped_dek' => $wrappedDek,
            'wrap_nonce' => $nonce,
            'wrap_tag' => $tag,
            'kdf_salt' => $salt,
            'kdf_params' => $kdfParams,
            'verification_hash' => hash('sha256', $dek),
        ]);

        RecoveryKey::create([
            'encryption_key_id' => $key->id,
            'label' => 'Initial-Recovery-Key',
            'fingerprint' => hash('sha256', $recoveryKey),
        ]);

        return $recoveryKey;
    }

    private function unwrap(KeyWrap $wrap, string $password): string
    {
        $kek = $this->deriveKek($password, $wrap->kdf_salt, $wrap->kdf_params);

        $dek = openssl_decrypt(
            $wrap->wrapped_dek,
            'aes-256-gcm',
            $kek,
            OPENSSL_RAW_DATA,
            $wrap->wrap_nonce,
            $wrap->wrap_tag,
        );

        \sodium_memzero($kek);

        if ($dek === false) {
            throw new CryptoException('Falsches Passwort oder Wrap beschädigt.');
        }

        if (! hash_equals($wrap->verification_hash, hash('sha256', $dek))) {
            \sodium_memzero($dek);
            throw new CryptoException('DEK-Verifikation fehlgeschlagen.');
        }

        return $dek;
    }

    /**
     * @param  array{memory_cost:int, time_cost:int, threads:int}  $params
     */
    private function deriveKek(string $password, string $salt, array $params): string
    {
        // Argon2id via sodium → 32 Byte Schlüssel
        return \sodium_crypto_pwhash(
            self::DEK_BYTES,
            $password,
            // sodium braucht genau SODIUM_CRYPTO_PWHASH_SALTBYTES (16) Bytes
            substr(str_pad($salt, \SODIUM_CRYPTO_PWHASH_SALTBYTES, "\0"), 0, \SODIUM_CRYPTO_PWHASH_SALTBYTES),
            $params['time_cost'],
            $params['memory_cost'] * 1024,
            \SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    private function activeWrapForUser(User $user): ?KeyWrap
    {
        return KeyWrap::query()
            ->whereHas('encryptionKey', fn ($q) => $q->where('is_active', true))
            ->where('user_id', $user->id)
            ->where('wrap_type', 'user_password')
            ->first();
    }

    private function putDekInSession(string $dek): void
    {
        Session::put(self::SESSION_KEY, \sodium_bin2hex($dek));
    }

    /**
     * Generiert einen lesbaren Recovery-Key in 8er-Gruppen (z. B. ABCD-EFGH-...).
     */
    private function generateRecoveryKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne 0/O/1/I
        $totalChars = (int) (self::RECOVERY_KEY_BYTES * 8 / 5); // ~52 Zeichen
        $raw = '';
        for ($i = 0; $i < $totalChars; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return rtrim(chunk_split($raw, 4, '-'), '-');
    }
}
