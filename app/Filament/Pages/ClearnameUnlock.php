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

class ClearnameUnlock extends Page implements HasForms
{
    use AuthorizedPage;
    use InteractsWithForms;

    protected static function requiredPermission(): ?string { return 'clearname.unlock'; }

    protected static ?string $navigationIcon = 'heroicon-o-lock-open';
    protected static ?string $navigationGroup = 'Klarnamen';
    protected static ?int $navigationSort = 10;
    protected static ?string $title = 'Klarnamen entsperren';
    protected static ?string $navigationLabel = 'Entsperren';
    protected static string $view = 'filament.pages.clearname-unlock';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('password')
                    ->label('Klarnamen-Passwort')
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('current-password'),
            ])
            ->statePath('data');
    }

    public function unlockAction(): Action
    {
        return Action::make('unlock')
            ->label('Entsperren')
            ->submit('unlock');
    }

    public function unlock(): void
    {
        $data = $this->form->getState();
        $crypto = app(CryptoService::class);
        $audit = app(AuditLogger::class);

        if (! $crypto->hasActiveWrap(auth()->user())) {
            Notification::make()
                ->danger()
                ->title('Kein Klarnamen-Passwort hinterlegt')
                ->body('Für Ihren Account ist noch kein Klarnamen-Passwort gesetzt. Bitte wenden Sie sich an den Admin.')
                ->send();

            return;
        }

        if (! $crypto->unlock(auth()->user(), $data['password'])) {
            Notification::make()
                ->danger()
                ->title('Falsches Klarnamen-Passwort')
                ->send();

            return;
        }

        $audit->logUser(auth()->user(), 'clearname.unlock');

        Notification::make()
            ->success()
            ->title('Klarnamen sind für diese Sitzung entsperrt')
            ->send();

        $this->reset('data');
    }

    public function lockAction(): Action
    {
        return Action::make('lock')
            ->label('Klarnamen sperren')
            ->color('gray')
            ->action(function () {
                app(CryptoService::class)->lock();
                Notification::make()->info()->title('Klarnamen gesperrt')->send();
            });
    }
}
