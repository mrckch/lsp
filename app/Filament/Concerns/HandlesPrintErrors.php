<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\PrintJob\Exceptions\PdfServiceUnavailableException;
use Filament\Notifications\Notification;

/**
 * Wandelt Throwables aus PDF-Aktionen in benutzerfreundliche Filament-Notifications.
 */
trait HandlesPrintErrors
{
    /**
     * @template T
     *
     * @param  callable():T  $action
     * @return T|null
     */
    protected static function runPrintAction(callable $action, string $contextLabel = 'PDF-Aktion'): mixed
    {
        try {
            return $action();
        } catch (PdfServiceUnavailableException $e) {
            Notification::make()
                ->danger()
                ->title('PDF-Service nicht erreichbar')
                ->body($e->getMessage()."\n\nBitte prüfe, ob der Gotenberg-Container läuft (`docker compose ps pdf`).")
                ->persistent()
                ->send();

            return null;
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title("$contextLabel fehlgeschlagen")
                ->body($e->getMessage())
                ->send();

            return null;
        }
    }
}
