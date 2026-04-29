<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\Adapters\SvwsApiImporter;
use App\Domain\Import\DTOs\ImportInput;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SvwsImporterStubTest extends TestCase
{
    #[Test]
    public function it_has_correct_key(): void
    {
        $this->assertEquals('svws_api', (new SvwsApiImporter)->key());
    }

    #[Test]
    public function it_throws_until_implemented(): void
    {
        $importer = new SvwsApiImporter;
        $this->expectException(\RuntimeException::class);
        $importer->validate(new ImportInput(filePath: '', filename: 'svws'));
    }
}
