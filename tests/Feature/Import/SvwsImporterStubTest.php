<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\Adapters\SvwsApiImporter;
use App\Domain\Import\DTOs\ImportInput;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke-Tests für den SvwsApiImporter (Adapter-Identität + Input-Validierung).
 * Vollständige Integration siehe SvwsApiImporterTest mit Http::fake.
 */
class SvwsImporterStubTest extends TestCase
{
    #[Test]
    public function it_has_correct_key(): void
    {
        $this->assertEquals('svws_api', app(SvwsApiImporter::class)->key());
    }

    #[Test]
    public function it_throws_when_input_has_no_source_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('benötigt eine ImportSource');

        app(SvwsApiImporter::class)->validate(
            new ImportInput(filePath: '', filename: 'svws_api', sourceId: null),
        );
    }
}
