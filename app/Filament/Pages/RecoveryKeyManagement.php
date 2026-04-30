<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crypto\CryptoService;
use App\Domain\Crypto\Models\RecoveryKey;
use App\Filament\Concerns\AuthorizedPage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Übersicht der Recovery-Keys + Regenerate-Action.
 *
 * Permission: clearname.recovery.regenerate (2FA-Pflicht).
 * Voraussetzung für Regenerate: Klarnamen-Session entsperrt (DEK liegt vor).
 *
 * Recovery-Keys werden NIE im Klartext gespeichert; angezeigt wird nur
 * Fingerprint + Status (active/used/revoked). Der Klartext erscheint
 * einmalig nach Regenerate und muss vom Admin sicher verwahrt werden.
 */
class RecoveryKeyManagement extends Page
{
    use AuthorizedPage;

    protected static function requiredPermission(): ?string
    {
        return 'clearname.recovery.regenerate';
    }

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Klarnamen';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Recovery-Keys';

    protected static ?string $navigationLabel = 'Recovery-Keys';

    protected static string $view = 'filament.pages.recovery-key-management';

    /** Klartext-Recovery-Key, einmalig nach Regenerate; sonst null. */
    public ?string $newRecoveryKey = null;

    public function getRecoveryKeys()
    {
        return RecoveryKey::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (RecoveryKey $r) => [
                'label' => $r->label,
                'fingerprint_short' => substr($r->fingerprint, 0, 12).'…',
                'created_at' => $r->created_at?->format('d.m.Y H:i'),
                'status' => $r->revoked_at !== null
                    ? 'revoked'
                    : ($r->used_at !== null ? 'used' : 'active'),
                'used_at' => $r->used_at?->format('d.m.Y H:i'),
                'revoked_at' => $r->revoked_at?->format('d.m.Y H:i'),
            ]);
    }

    public function regenerateAction(): Action
    {
        return Action::make('regenerate')
            ->label('Neuen Recovery-Key erzeugen')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(
                'Erzeugt einen neuen Recovery-Key und invalidiert den alten. '.
                'Der neue Key wird einmalig angezeigt — bitte sofort sicher verwahren. '.
                'Voraussetzung: Klarnamen-Session ist entsperrt.',
            )
            ->action(function () {
                $crypto = app(CryptoService::class);
                if (! $crypto->isUnlocked()) {
                    Notification::make()->danger()
                        ->title('Klarnamen-Session ist gesperrt')
                        ->body('Bitte zuerst Klarnamen entsperren, damit die DEK aus Ihrer Session genommen werden kann.')
                        ->send();

                    return;
                }

                try {
                    $this->newRecoveryKey = $crypto->regenerateRecoveryKey();
                } catch (\Throwable $e) {
                    Notification::make()->danger()
                        ->title('Erzeugung fehlgeschlagen')
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                app(AuditLogger::class)->logUser(auth()->user(), 'clearname.recovery.regenerated');

                Notification::make()->success()
                    ->title('Neuer Recovery-Key erzeugt')
                    ->body('Bitte den unten angezeigten Key sicher verwahren — er wird nicht erneut angezeigt.')
                    ->send();
            });
    }

    public function dismissKeyAction(): Action
    {
        return Action::make('dismissKey')
            ->label('Ich habe den Key sicher verwahrt')
            ->color('success')
            ->action(function () {
                $this->newRecoveryKey = null;
            });
    }
}
