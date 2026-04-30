<?php

declare(strict_types=1);

namespace Tests\Feature\Print;

use App\Domain\PrintJob\Exceptions\PdfServiceUnavailableException;
use App\Domain\PrintJob\GotenbergClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GotenbergClientTest extends TestCase
{
    #[Test]
    public function ping_returns_true_when_health_endpoint_responds_ok(): void
    {
        Http::fake(['*/health' => Http::response('OK', 200)]);
        $this->assertTrue((new GotenbergClient('http://pdf:3000'))->ping());
    }

    #[Test]
    public function ping_returns_false_when_health_endpoint_errors(): void
    {
        Http::fake(['*/health' => Http::response('boom', 500)]);
        $this->assertFalse((new GotenbergClient('http://pdf:3000'))->ping());
    }

    #[Test]
    public function ping_returns_false_when_unreachable(): void
    {
        Http::fake(fn (Request $r) => throw new ConnectionException('cannot connect'));
        $this->assertFalse((new GotenbergClient('http://pdf:3000'))->ping());
    }

    #[Test]
    public function html_to_pdf_throws_pdf_service_unavailable_on_connection_failure(): void
    {
        Http::fake(fn (Request $r) => throw new ConnectionException('connection refused'));

        $this->expectException(PdfServiceUnavailableException::class);
        $this->expectExceptionMessage('PDF-Service nicht erreichbar');

        (new GotenbergClient('http://pdf:3000'))->htmlToPdf('<h1>X</h1>');
    }

    #[Test]
    public function html_to_pdf_throws_pdf_service_unavailable_on_bad_response(): void
    {
        Http::fake(['*/forms/chromium/convert/html' => Http::response('Internal Error', 500)]);

        $this->expectException(PdfServiceUnavailableException::class);
        $this->expectExceptionMessage('HTTP 500');

        (new GotenbergClient('http://pdf:3000'))->htmlToPdf('<h1>X</h1>');
    }

    #[Test]
    public function html_to_pdf_returns_body_on_success(): void
    {
        Http::fake(['*/forms/chromium/convert/html' => Http::response('PDFBYTES', 200)]);
        $this->assertEquals('PDFBYTES', (new GotenbergClient('http://pdf:3000'))->htmlToPdf('<h1>X</h1>'));
    }
}
