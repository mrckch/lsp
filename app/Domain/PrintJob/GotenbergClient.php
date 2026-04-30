<?php

declare(strict_types=1);

namespace App\Domain\PrintJob;

use App\Domain\PrintJob\Exceptions\PdfServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Schlanker HTTP-Client für den Gotenberg-PDF-Service.
 *
 * Spec: https://gotenberg.dev/docs/routes#convert-html-into-pdf-route
 *
 * Connection-Probleme werden in PdfServiceUnavailableException umgewandelt,
 * damit die UI sie freundlich darstellen kann (statt 500 / unhandled).
 */
class GotenbergClient
{
    public function __construct(private readonly string $baseUrl) {}

    /**
     * Konvertiert HTML+CSS zu PDF-Bytes.
     *
     * @throws PdfServiceUnavailableException
     */
    public function htmlToPdf(string $html, ?string $css = null, array $options = []): string
    {
        $url = rtrim($this->baseUrl, '/').'/forms/chromium/convert/html';

        $request = $this->client();

        if ($css !== null && $css !== '') {
            $request->attach('files', $css, 'styles.css');
        }
        $request->attach('files', $this->wrapHtml($html, $css !== null && $css !== ''), 'index.html');

        foreach ($options as $key => $value) {
            $request = $request->attach($key, (string) $value);
        }

        try {
            $response = $request->post($url);
        } catch (ConnectionException $e) {
            throw PdfServiceUnavailableException::notReachable($this->baseUrl, $e->getMessage());
        }

        if (! $response->successful()) {
            throw PdfServiceUnavailableException::badResponse($response->status(), $response->body());
        }

        return $response->body();
    }

    /**
     * Schneller Health-Check (Gotenberg /health endpoint).
     * Liefert true bei Erreichbarkeit, false sonst (wirft nicht).
     */
    public function ping(): bool
    {
        try {
            $response = Http::timeout(5)->get(rtrim($this->baseUrl, '/').'/health');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    private function client(): PendingRequest
    {
        return Http::timeout(60)->asMultipart();
    }

    private function wrapHtml(string $html, bool $hasExternalCss): string
    {
        if (str_contains($html, '<html')) {
            return $html;
        }
        $cssLink = $hasExternalCss ? '<link rel="stylesheet" href="styles.css">' : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8">$cssLink</head>
<body>$html</body>
</html>
HTML;
    }
}
