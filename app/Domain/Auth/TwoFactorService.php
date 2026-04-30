<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Models\TwoFactorSecret;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP-basierte 2FA. Geheimer Schlüssel wird via Laravel-Encryption am Wert gespeichert.
 */
final class TwoFactorService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Erzeugt einen neuen Secret + speichert (unbestätigt) und liefert das otpauth-URI für den QR-Code.
     */
    public function startEnrollment(User $user, string $issuer): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        TwoFactorSecret::updateOrCreate(
            ['user_id' => $user->id],
            [
                'secret_encrypted' => encrypt($secret),
                'recovery_codes_encrypted' => null,
                'confirmed_at' => null,
            ],
        );

        $accountLabel = $user->username.'@'.$issuer;
        $uri = $this->google2fa->getQRCodeUrl($issuer, $accountLabel, $secret);

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => $this->qrSvg($uri),
        ];
    }

    /**
     * Bestätigt die Einrichtung mit einem TOTP-Code.
     */
    public function confirmEnrollment(User $user, string $code): bool
    {
        $secretRow = TwoFactorSecret::where('user_id', $user->id)->first();
        if ($secretRow === null) {
            return false;
        }

        $secret = decrypt($secretRow->secret_encrypted);
        if (! $this->google2fa->verifyKey($secret, $code)) {
            return false;
        }

        $recoveryCodes = $this->generateRecoveryCodes(8);

        $secretRow->update([
            'recovery_codes_encrypted' => encrypt(json_encode($recoveryCodes)),
            'confirmed_at' => now(),
        ]);

        $user->update([
            'two_factor_enabled' => true,
            'last_2fa_at' => now(),
        ]);

        return true;
    }

    public function verify(User $user, string $code): bool
    {
        $secretRow = TwoFactorSecret::where('user_id', $user->id)->whereNotNull('confirmed_at')->first();
        if ($secretRow === null) {
            return false;
        }

        $secret = decrypt($secretRow->secret_encrypted);

        // Erst TOTP-Code prüfen
        if ($this->google2fa->verifyKey($secret, $code)) {
            $user->update(['last_2fa_at' => now()]);

            return true;
        }

        // Sonst: Recovery-Code prüfen
        $recoveryCodes = json_decode(decrypt($secretRow->recovery_codes_encrypted), true) ?? [];
        $normalized = strtoupper(preg_replace('/\s|-/', '', $code) ?? '');

        foreach ($recoveryCodes as $i => $rc) {
            if (hash_equals(strtoupper(preg_replace('/\s|-/', '', $rc) ?? ''), $normalized)) {
                unset($recoveryCodes[$i]);
                $secretRow->update(['recovery_codes_encrypted' => encrypt(json_encode(array_values($recoveryCodes)))]);
                $user->update(['last_2fa_at' => now()]);

                return true;
            }
        }

        return false;
    }

    public function disable(User $user): void
    {
        TwoFactorSecret::where('user_id', $user->id)->delete();
        $user->update(['two_factor_enabled' => false, 'last_2fa_at' => null]);
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(int $count): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))).'-'.strtoupper(bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    private function qrSvg(string $uri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($uri);
    }
}
