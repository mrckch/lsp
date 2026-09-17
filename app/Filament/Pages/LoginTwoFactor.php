<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Auth\TwoFactorService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Login-2FA-Challenge: Nach der Passwort-Anmeldung bestätigt ein Nutzer mit
 * aktiviertem 2FA hier den TOTP-Code (oder einen Recovery-Code). Erst danach
 * wird die Session als 2FA-bestätigt markiert (`login_2fa_ok`) und das Panel
 * durch EnforceLoginTwoFactor freigegeben.
 */
class LoginTwoFactor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = '2-Faktor-Bestätigung';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'login-two-factor';

    protected static string $view = 'filament.pages.login-two-factor';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->two_factor_enabled
            && session('login_2fa_ok') !== true;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')
                ->label('Code aus Ihrer Authenticator-App (oder Recovery-Code)')
                ->required()
                ->autofocus(),
        ])->statePath('data');
    }

    public function verifyAction(): Action
    {
        return Action::make('verify')->label('Bestätigen')->submit('verify');
    }

    public function verify(): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $key = 'login-2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            Notification::make()->danger()
                ->title('Zu viele Versuche')
                ->body('Bitte in einer Minute erneut versuchen.')
                ->send();

            return;
        }

        $code = trim((string) ($this->form->getState()['code'] ?? ''));

        if (! app(TwoFactorService::class)->verify($user, $code)) {
            RateLimiter::hit($key, 60);
            Notification::make()->danger()->title('Code ungültig')->send();

            return;
        }

        RateLimiter::clear($key);
        session()->put('login_2fa_ok', true);

        $this->redirect('/admin');
    }
}
