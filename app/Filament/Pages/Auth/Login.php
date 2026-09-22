<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;

/**
 * Login akzeptiert Username ODER E-Mail gleichberechtigt. Enthält der Wert
 * ein gültiges E-Mail-Format, wird über die Spalte `email` authentifiziert,
 * sonst über `username`.
 *
 * Setzt zudem das Login-2FA-Gate zurück, damit jeder frische Login (bei
 * aktiviertem 2FA) erneut den TOTP-Code verlangt (siehe EnforceLoginTwoFactor).
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response !== null) {
            session()->forget('login_2fa_ok');
        }

        return $response;
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Username oder E-Mail')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $login = trim((string) $data['email']);
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [
            $field => $login,
            'password' => $data['password'],
        ];
    }
}
