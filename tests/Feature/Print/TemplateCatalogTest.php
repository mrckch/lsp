<?php

declare(strict_types=1);

namespace Tests\Feature\Print;

use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\PrintJobRunner;
use App\Domain\PrintTemplate\TemplateCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateCatalogTest extends TestCase
{
    #[Test]
    public function it_lists_known_template_types(): void
    {
        $options = TemplateCatalog::options();
        $this->assertArrayHasKey('student_feedback', $options);
        $this->assertArrayHasKey('login_codes', $options);
        $this->assertArrayHasKey('student_history', $options);
        $this->assertArrayHasKey('support_list', $options);
    }

    #[Test]
    public function each_type_has_variables_and_sample_data(): void
    {
        foreach (TemplateCatalog::types() as $key => $meta) {
            $this->assertArrayHasKey('variables', $meta, "Type $key needs variables");
            $this->assertArrayHasKey('sample', $meta, "Type $key needs sample");
            $this->assertNotEmpty($meta['variables']);
            $this->assertNotEmpty($meta['sample']);
        }
    }

    #[Test]
    public function for_returns_null_for_unknown_type(): void
    {
        $this->assertNull(TemplateCatalog::for('does_not_exist'));
    }

    #[Test]
    public function sample_data_renders_through_runner_without_errors(): void
    {
        // Wir brauchen Gotenberg nicht – nur den Variable-Replacement-Pfad testen.
        $runner = new PrintJobRunner(new class('http://x') extends GotenbergClient
        {
            public function htmlToPdf(string $html, ?string $css = null, array $options = []): string
            {
                return $html;
            }
        });

        foreach (TemplateCatalog::types() as $type => $meta) {
            $template = '<h1>{{school_name}}</h1>';
            // Für jeden Typ die Variable verwenden, die alle haben (school_name)
            $rendered = $runner->renderTemplate($template, $meta['sample']);
            $this->assertStringContainsString('Beispielschule', $rendered, "Type $type renders sample school_name");
        }
    }
}
