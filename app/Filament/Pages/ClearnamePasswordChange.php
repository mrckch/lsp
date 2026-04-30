<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crypto\CryptoService;
use App\Filament\Concerns\AuthorizedPage;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ClearnamePasswordChange extends Page implements HasForms
{
    use AuthorizedPage;
    use InteractsWithForms;

    protected static function requiredPermission(): ?string
    {
        return 'clearname.password.change';
    }

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Klarnamen';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Klarnamen-Passwort ändern';

    protected static ?string $navigationLabel = 'Passwort ändern';

    protected static string $view = 'filament.pages.clearname-password-change';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('old_password')
                    ->label('Aktuelles Klarnamen-Passwort')
                    ->password()->revealable()->required(),
                TextInput::make('new_password')
                    ->label('Neues Klarnamen-Passwort')
                    ->password()->revealable()->required()->minLength(12),
                TextInput::make('new_password_confirmation')
                    ->label('Neues Passwort wiederholen')
                    ->password()->revealable()->required()
                    ->same('new_password'),
            ])
            ->statePath('data');
    }

    public function changeAction(): Action
    {
        return Action::make('change')->label('Ändern')->submit('change');
    }

    public function change(): void
    {
        $data = $this->form->getState();
        $crypto = app(CryptoService::class);

        $ok = $crypto->changeUserPassword(auth()->user(), $data['old_password'], $data['new_password']);

        if (! $ok) {
            Notification::make()->danger()->title('Aktuelles Passwort ist falsch')->send();

            return;
        }

        app(AuditLogger::class)->logUser(auth()->user(), 'clearname.password.change');

        Notification::make()->success()->title('Klarnamen-Passwort geändert')->send();

        $this->reset('data');
    }
}
