<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\TwoFactorService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pflicht-2FA-Setup für User, deren UserGroup `force_two_factor=true` hat.
 * Wird durch EnforceTwoFactorIfRequired-Middleware erzwungen.
 */
class ForceTwoFactorSetup extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = '2-Faktor-Authentifizierung einrichten (Pflicht)';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.force-two-factor-setup';

    public ?array $data = [];

    public ?string $qrSvg = null;

    public ?string $secret = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();
        if ($u === null || $u->two_factor_enabled) {
            return false;
        }

        return $u->userGroups()->where('force_two_factor', true)->exists();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')
                ->label('TOTP-Code aus Ihrer Authenticator-App')
                ->required()->numeric()->length(6),
        ])->statePath('data');
    }

    public function startAction(): Action
    {
        return Action::make('start')
            ->label('Einrichtung starten')
            ->action(function () {
                $result = app(TwoFactorService::class)->startEnrollment(
                    auth()->user(), config('app.name', 'LSP'),
                );
                $this->qrSvg = $result['qr_svg'];
                $this->secret = $result['secret'];
            });
    }

    public function confirmAction(): Action
    {
        return Action::make('confirm')->label('Einrichtung bestätigen')->submit('confirm');
    }

    public function confirm(): void
    {
        $data = $this->form->getState();
        $user = auth()->user();

        if (! app(TwoFactorService::class)->confirmEnrollment($user, $data['code'])) {
            Notification::make()->danger()->title('TOTP-Code ungültig')->send();

            return;
        }

        app(AuditLogger::class)->logUser($user->refresh(), 'two_factor.enabled.forced');

        Notification::make()->success()
            ->title('2FA aktiviert')
            ->body('Sie können das System jetzt nutzen.')
            ->send();

        $this->redirect('/admin');
    }
}
