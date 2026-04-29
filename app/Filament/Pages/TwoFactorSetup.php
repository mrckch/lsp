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

class TwoFactorSetup extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Mein Konto';
    protected static ?int $navigationSort = 90;
    protected static ?string $title = '2-Faktor-Authentifizierung';
    protected static ?string $navigationLabel = '2FA';
    protected static string $view = 'filament.pages.two-factor-setup';

    public ?array $data = [];
    public ?string $qrSvg = null;
    public ?string $secret = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('TOTP-Code aus Ihrer Authenticator-App')
                    ->required()
                    ->numeric()
                    ->length(6),
            ])
            ->statePath('data');
    }

    public function startAction(): Action
    {
        return Action::make('start')
            ->label('Einrichtung starten')
            ->action(function () {
                $result = app(TwoFactorService::class)->startEnrollment(auth()->user(), config('app.name', 'LSP'));
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

        if (! app(TwoFactorService::class)->confirmEnrollment(auth()->user(), $data['code'])) {
            Notification::make()->danger()->title('TOTP-Code ungültig')->send();

            return;
        }

        app(AuditLogger::class)->logUser(auth()->user(), 'two_factor.enabled');

        Notification::make()->success()
            ->title('2FA aktiviert')
            ->body('Bitte bewahren Sie Ihre Recovery-Codes (im nächsten Schritt) sicher auf.')
            ->send();

        $this->qrSvg = null;
        $this->secret = null;
        $this->reset('data');
    }

    public function disableAction(): Action
    {
        return Action::make('disable')
            ->label('2FA deaktivieren')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function () {
                app(TwoFactorService::class)->disable(auth()->user());
                app(AuditLogger::class)->logUser(auth()->user(), 'two_factor.disabled');
                Notification::make()->info()->title('2FA deaktiviert')->send();
            });
    }
}
