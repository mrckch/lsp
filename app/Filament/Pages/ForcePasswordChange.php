<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\OnboardingService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

/**
 * Erst-Login-Seite: User muss sein Initial-Passwort durch ein eigenes ersetzen,
 * bevor er das System nutzen darf.
 */
class ForcePasswordChange extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = 'Passwort ändern (Pflicht)';
    protected static bool $shouldRegisterNavigation = false;
    protected static string $view = 'filament.pages.force-password-change';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u !== null && (bool) $u->must_change_password;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('current_password')
                ->label('Aktuelles (Initial-)Passwort')
                ->password()->revealable()->required(),
            TextInput::make('new_password')
                ->label('Neues Passwort')
                ->password()->revealable()->required()->minLength(12),
            TextInput::make('new_password_confirmation')
                ->label('Neues Passwort wiederholen')
                ->password()->revealable()->required()
                ->same('new_password'),
        ])->statePath('data');
    }

    public function changeAction(): Action
    {
        return Action::make('change')->label('Passwort setzen')->submit('change');
    }

    public function change(): void
    {
        $data = $this->form->getState();
        $user = auth()->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            Notification::make()->danger()->title('Aktuelles Passwort ist falsch')->send();

            return;
        }

        app(OnboardingService::class)->applyChosenPassword($user, $data['new_password']);
        app(AuditLogger::class)->logUser($user->refresh(), 'users.password.changed.first_login');

        Notification::make()->success()
            ->title('Passwort gesetzt')
            ->body('Sie können das System jetzt nutzen. Wir empfehlen die Aktivierung der 2-Faktor-Authentifizierung.')
            ->send();

        $this->redirect('/admin');
    }
}
