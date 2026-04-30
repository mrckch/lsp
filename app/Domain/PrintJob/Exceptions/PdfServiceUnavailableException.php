<?php

declare(strict_types=1);

namespace App\Domain\PrintJob\Exceptions;

/**
 * Wird geworfen, wenn der PDF-Service (Gotenberg) nicht erreichbar oder fehlerhaft ist.
 * UI-Schicht soll dies abfangen und eine benutzerfreundliche Meldung anzeigen.
 */
class PdfServiceUnavailableException extends \RuntimeException
{
    public static function notReachable(string $url, ?string $cause = null): self
    {
        $msg = "PDF-Service nicht erreichbar ($url)";
        if ($cause !== null) {
            $msg .= ": $cause";
        }

        return new self($msg);
    }

    public static function badResponse(int $status, string $body): self
    {
        return new self("PDF-Service antwortete mit HTTP $status: ".mb_substr($body, 0, 200));
    }
}
