<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Domain\PrintTemplate\Models\PrintTemplate;
use App\Domain\PrintTemplate\TemplateCatalog;
use Database\Seeders\DefaultPrintTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DefaultPrintTemplatesSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_one_baseline_template_per_catalog_type_without_a_user(): void
    {
        // Kein User angelegt — Erst-Seed vor dem Setup-Wizard.
        $this->seed(DefaultPrintTemplatesSeeder::class);

        $seededTypes = PrintTemplate::query()->pluck('type')->unique()->all();
        foreach (array_keys(TemplateCatalog::types()) as $type) {
            $this->assertContains($type, $seededTypes, "Kein Default-Template für Typ {$type}");
        }

        $templates = PrintTemplate::query()->with('currentVersion')->get();
        $this->assertCount(count(TemplateCatalog::types()), $templates);
        foreach ($templates as $tpl) {
            $this->assertTrue($tpl->is_system);
            $this->assertNotNull($tpl->current_version_id, "{$tpl->key} ohne aktuelle Version");
            $this->assertNull($tpl->currentVersion->created_by_user_id);
            $this->assertNotEmpty($tpl->currentVersion->html_content);
        }
    }

    #[Test]
    public function it_is_idempotent_and_keeps_edits(): void
    {
        $this->seed(DefaultPrintTemplatesSeeder::class);
        $before = PrintTemplate::query()->count();
        $tpl = PrintTemplate::query()->firstWhere('key', 'rueckmeldung');
        $tpl->currentVersion->update(['html_content' => '<h1>BEARBEITET</h1>']);

        $this->seed(DefaultPrintTemplatesSeeder::class);

        $this->assertSame($before, PrintTemplate::query()->count());
        $this->assertSame(1, $tpl->versions()->count());
        $this->assertStringContainsString('BEARBEITET', $tpl->currentVersion->refresh()->html_content);
    }
}
