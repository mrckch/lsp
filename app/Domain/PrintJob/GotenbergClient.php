<?php

declare(strict_types=1);

namespace App\Domain\PrintJob;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Schlanker HTTP-Client für den Gotenberg-PDF-Service.
 *
 * Spec: https://gotenberg.dev/docs/routes#convert-html-into-pdf-route
 */
class GotenbergClient
{
    public function __construct(private readonly string $baseUrl) {}

    /**
     * Konvertiert HTML+CSS zu PDF-Bytes.
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

        $response = $request->post($url);

        if (! $response->successful()) {
            throw new \RuntimeException("Gotenberg-Fehler {$response->status()}: ".$response->body());
        }

        return $response->body();
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
