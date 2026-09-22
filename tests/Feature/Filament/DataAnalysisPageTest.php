<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Analytics\AnalysisCsvExporter;
use App\Domain\Analytics\AnalysisDataset;
use App\Domain\Analytics\AnalysisFilter;
use App\Domain\Analytics\AnalysisReport;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Crypto\CryptoService;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\PrintJob\Models\GeneratedDocument;
use App\Filament\Pages\DataAnalysisPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Analytics\AnalysisFixtures;
use Tests\TestCase;

class DataAnalysisPageTest extends TestCase
{
    use AnalysisFixtures;
    use RefreshDatabase;

    /** @var array{html: ?string, files: array<string, string>} */
    private array $captured = ['html' => null, 'files' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAnalysis();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-05 10:15:00');

        $captured = &$this->captured;
        $this->app->instance(GotenbergClient::class, new class('http://pdf', $captured) extends GotenbergClient
        {
            public function __construct(string $url, private array &$captured)
            {
                parent::__construct($url);
            }

            public function htmlToPdf(string $html, ?string $css = null, array $options = [], array $extraFiles = []): string
            {
                $this->captured = ['html' => $html, 'files' => $extraFiles];

                return '%PDF-fake';
            }
        });
    }

    #[Test]
    public function access_follows_cohort_permission(): void
    {
        $this->assertTrue(DataAnalysisPage::canAccess());
        $this->actingAs($this->teacher);
        $this->assertTrue(DataAnalysisPage::canAccess());
        $this->actingAs($this->user('sek', 'Sekretariat'));
        $this->assertFalse(DataAnalysisPage::canAccess());
        $this->get(DataAnalysisPage::getUrl())->assertForbidden();
    }

    #[Test]
    public function admin_compares_all_classes(): void
    {
        Livewire::test(DataAnalysisPage::class)
            ->assertOk()
            ->assertSee('Klassen vergleichen')
            ->assertSee('Vergleich')
            ->assertSee('5a')
            ->assertSee('5b')
            ->assertSee('Clara Test: LQ 60', false)
            ->assertSee('Gesamt');
    }

    #[Test]
    public function teacher_sees_only_own_class(): void
    {
        $this->actingAs($this->teacher);

        Livewire::test(DataAnalysisPage::class)
            ->assertSee('Anna Test: LQ 80', false)
            ->assertDontSee('Clara Test')
            ->assertSee('n = 2')
            ->assertDontSee('n = 3');          // 5b (3 SuS) liegt außerhalb des Scopes
    }

    #[Test]
    public function filters_come_from_url_and_presets_split_by_gender(): void
    {
        Livewire::withQueryParams(['f' => ['group_by' => 'gender']])
            ->test(DataAnalysisPage::class)
            ->assertSet('filters.group_by', 'gender')
            ->assertSee('n = 3')               // Mädchen
            ->assertDontSee('n = 1')
            ->call('applyPreset', 'class_gender')
            ->assertSet('filters.group_by', 'learning_group')
            ->assertSet('filters.secondary_group_by', 'gender')
            ->assertSet('byGender', true)
            ->assertSee('n = 1');              // z. B. 5a · Mädchen
    }

    #[Test]
    public function drilldown_lists_students_of_group_within_scope(): void
    {
        Livewire::test(DataAnalysisPage::class)
            ->mountAction('groupStudents', ['group' => 'g'.$this->g5b->id])
            ->assertSee('5b (n = 3)')
            ->assertSee('Clara Test')
            ->assertSee('Förderbedarf');

        $this->actingAs($this->teacher);
        Livewire::test(DataAnalysisPage::class)
            ->mountAction('groupStudents', ['group' => 'g'.$this->g5b->id])
            ->assertSee('nicht mehr vorhanden')
            ->assertDontSee('Clara Test');
    }

    #[Test]
    public function pdf_with_student_lists_is_stored_and_audited(): void
    {
        Livewire::test(DataAnalysisPage::class)
            ->callAction('exportPdf', ['orientation' => 'landscape', 'with_lists' => true])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('datenanalyse-20261005-1015.pdf');

        $this->assertStringContainsString('size: A4 landscape', $this->captured['html']);
        $this->assertStringContainsString('Testschule', $this->captured['html']);
        $this->assertStringContainsString('Schülerlisten je Gruppe', $this->captured['html']);
        $this->assertStringContainsString('Clara Test', $this->captured['html']);
        $this->assertStringContainsString('Vertraulich – enthält Klarnamen', $this->captured['files']['footer.html']);

        $doc = GeneratedDocument::query()->sole();
        $this->assertTrue($doc->includes_clearnames);
        Storage::disk('local')->assertExists($doc->file_path);
        $log = AuditLog::query()->where('action', 'analysis.export_pdf')->sole();
        $this->assertTrue((bool) $log->includes_clearnames);
    }

    #[Test]
    public function pdf_with_names_requires_unlocked_clearnames(): void
    {
        app(CryptoService::class)->lock();

        Livewire::test(DataAnalysisPage::class)
            ->callAction('exportPdf', ['orientation' => 'portrait', 'with_lists' => true])
            ->assertNotified('Klarnamen-Session gesperrt');

        $this->assertNull($this->captured['html']);
        $this->assertSame(0, GeneratedDocument::query()->count());

        // Ohne Schülerlisten geht es auch gesperrt
        Livewire::test(DataAnalysisPage::class)
            ->callAction('exportPdf', ['orientation' => 'portrait', 'with_lists' => false])
            ->assertFileDownloaded();
        $this->assertStringNotContainsString('Schülerlisten je Gruppe', $this->captured['html']);
        $this->assertFalse(GeneratedDocument::query()->sole()->includes_clearnames);
    }

    #[Test]
    public function csv_export_is_audited_and_contains_stats(): void
    {
        Livewire::test(DataAnalysisPage::class)
            ->callAction('exportCsv', ['with_values' => true, 'with_names' => false])
            ->assertFileDownloaded('datenanalyse-20261005-1015.csv');
        $this->assertFalse((bool) AuditLog::query()->where('action', 'analysis.export_csv')->sole()->includes_clearnames);

        $filter = AnalysisFilter::fromArray([]);
        $dist = app(AnalysisReport::class)->groupedDistribution(app(AnalysisDataset::class)->rows($filter, $this->admin), 'learning_group');
        $csv = app(AnalysisCsvExporter::class)->toCsv($dist, $filter->describe(), true, false);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Gruppe;Untergruppe;n;Mittelwert;SD;Median', $csv);
        $this->assertStringContainsString('5b;;3;88,3;', $csv);
        $this->assertStringContainsString('Gesamt;;5;89,0;', $csv);
        $this->assertStringNotContainsString('Clara Test', $csv);   // ohne Namen nur Codes
    }
}
