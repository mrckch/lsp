<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\AnalysisDataset;
use App\Domain\Analytics\AnalysisFilter;
use App\Domain\Analytics\AnalysisReport;
use App\Domain\Analytics\DistributionStats;
use App\Domain\Analytics\ItemAnalysis;
use App\Domain\Attempt\Models\AttemptAnswer;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\PrintJob\GotenbergClient;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use App\Filament\Pages\DataAnalysisPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verteilung vs. Norm, Tempo & Genauigkeit, Satzanalyse.
 * 4 Sätze (Lösung: richtig, falsch, richtig, falsch); Antworten je Schüler siehe setUp().
 */
class AnalysisPhase3Test extends TestCase
{
    use AnalysisFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAnalysis();

        $qid = $this->autumn->questionnaire_id;
        $questions = [];
        foreach (['richtig', 'falsch', 'richtig', 'falsch'] as $i => $solution) {
            $questions[$i + 1] = QuestionnaireQuestion::create([
                'questionnaire_id' => $qid, 'sort_order' => $i + 1, 'question_text' => 'S'.($i + 1), 'correct_answer' => $solution,
            ]);
        }

        // Satz => gegebene Antwort
        $given = [
            'Anna' => [1 => 'richtig', 2 => 'richtig', 3 => 'richtig', 4 => 'falsch'],   // 4 bearbeitet, 3 richtig
            'Ben' => [1 => 'richtig', 2 => 'falsch'],                                      // 2, 2
            'Clara' => [1 => 'falsch', 3 => 'richtig'],                                    // 2, 1 (Satz 2 übersprungen)
            'Dora' => [1 => 'richtig', 2 => 'falsch', 3 => 'richtig'],                     // 3, 3
            'Emil' => [1 => 'falsch', 2 => 'richtig', 3 => 'falsch', 4 => 'falsch'],       // 4, 1
        ];
        foreach ($given as $name => $answers) {
            $attempt = TestAttempt::query()->where('student_id', $this->students[$name]->id)->sole();
            $correct = 0;
            foreach ($answers as $nr => $answer) {
                $ok = $answer === $questions[$nr]->correct_answer;
                $correct += (int) $ok;
                AttemptAnswer::create(['test_attempt_id' => $attempt->id, 'question_id' => $questions[$nr]->id, 'given_answer' => $answer, 'is_correct' => $ok]);
            }
            $attempt->update(['score_raw' => $correct]);
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        return app(AnalysisDataset::class)->rows(AnalysisFilter::fromArray([]), $this->admin);
    }

    #[Test]
    public function normal_cdf_matches_known_values(): void
    {
        $this->assertEqualsWithDelta(0.5, DistributionStats::normalCdf(100), 1e-6);
        $this->assertEqualsWithDelta(0.158655, DistributionStats::normalCdf(85), 1e-5);
        $this->assertEqualsWithDelta(0.022750, DistributionStats::normalCdf(70), 1e-5);
    }

    #[Test]
    public function histogram_counts_all_values_and_compares_bands_with_norm(): void
    {
        $h = app(AnalysisReport::class)->histogram($this->rows());

        $this->assertSame(5, $h['n']);
        $this->assertSame(5, array_sum(array_column($h['bins'], 'count')));
        $this->assertSame(1, collect($h['bins'])->firstWhere('from', 60)['count']);   // Clara 60
        $shares = collect($h['shares'])->keyBy('severity');
        // LQ ≤ 69 laut Norm: Φ((69,5 − 100) / 15) ≈ 2,1 %; 70–84: ≈ 13,0 %
        $this->assertEqualsWithDelta(2.10, $shares['foerderbedarf']['expected_pct'], 0.01);
        $this->assertEqualsWithDelta(12.97, $shares['auffaellig']['expected_pct'], 0.01);
        $this->assertSame(20.0, $shares['foerderbedarf']['observed_pct']);
        $this->assertEqualsWithDelta(100.0, $shares->sum('expected_pct'), 0.001);
    }

    #[Test]
    public function speed_accuracy_splits_students_at_the_medians(): void
    {
        $sa = app(AnalysisReport::class)->speedAccuracy($this->rows());

        $this->assertSame(5, $sa['n']);
        $this->assertSame(3.0, $sa['median_x']);
        $this->assertSame(25.0, $sa['median_y']);
        $this->assertSame(['Emil Test'], array_column($sa['fast_inaccurate'], 'name'));
        $this->assertSame(['Ben Test', 'Dora Test'], array_column($sa['slow_accurate'], 'name'));
        $this->assertSame(
            ['fast_accurate' => 1, 'fast_inaccurate' => 1, 'slow_accurate' => 2, 'slow_inaccurate' => 1],
            collect($sa['quadrants'])->pluck('count', 'key')->all(),
        );
    }

    #[Test]
    public function item_analysis_reports_solution_rate_skips_and_reach(): void
    {
        $ia = app(ItemAnalysis::class)->analyse($this->rows());

        $this->assertSame(5, $ia['attempts']);
        $items = collect($ia['items'])->keyBy('nr');
        $this->assertSame([5, 4, 4, 2], $items->pluck('answered')->values()->all());
        $this->assertSame([60.0, 50.0, 75.0, 100.0], $items->pluck('solution_pct')->values()->all());
        $this->assertSame(1, $items[2]['skipped']);                  // Clara hat Satz 2 ausgelassen
        $this->assertSame([100.0, 100.0, 80.0, 40.0], $items->pluck('reached_pct')->values()->all());
        $this->assertSame([true, true, false, false], $items->pluck('hard')->values()->all());   // Satz 4: zu wenige Antworten
        $this->assertSame(3, $ia['median_reached']);
        $this->assertSame(2, $ia['hard_count']);
    }

    #[Test]
    public function page_tabs_render_new_views_and_gender_note(): void
    {
        Livewire::test(DataAnalysisPage::class)
            ->call('setTab', 'verteilung')
            ->assertSee('laut Norm erwartet')
            ->call('setTab', 'tempo')
            ->assertSee('Schnell, aber fehlerhaft')
            ->assertSee('Emil Test')
            ->call('setTab', 'saetze')
            ->assertSee('Lösungsquote')
            ->assertSee('die Hälfte der Schüler:innen kam mindestens bis Satz 3');

        Livewire::test(DataAnalysisPage::class)
            ->assertDontSee('geschlechtsspezifisch normiert');
        Livewire::withQueryParams(['f' => ['group_by' => 'gender']])
            ->test(DataAnalysisPage::class)
            ->assertSee('geschlechtsspezifisch normiert');
    }

    #[Test]
    public function pdf_contains_the_new_views(): void
    {
        Storage::fake('local');
        $captured = null;
        $this->app->instance(GotenbergClient::class, new class('http://pdf', $captured) extends GotenbergClient
        {
            public function __construct(string $url, private ?string &$captured)
            {
                parent::__construct($url);
            }

            public function htmlToPdf(string $html, ?string $css = null, array $options = [], array $extraFiles = []): string
            {
                $this->captured = $html;

                return '%PDF-fake';
            }
        });

        Livewire::test(DataAnalysisPage::class)
            ->callAction('exportPdf', ['views' => ['verteilung', 'tempo', 'saetze'], 'orientation' => 'portrait', 'with_lists' => false, 'with_names' => false])
            ->assertFileDownloaded();

        $this->assertStringContainsString('Verteilung im Vergleich zur Norm', $captured);
        $this->assertStringContainsString('Tempo &amp; Genauigkeit', $captured);
        $this->assertStringContainsString('Satzanalyse', $captured);
        $this->assertStringNotContainsString('Emil Test', $captured);          // ohne Klarnamen
        $this->assertStringContainsString($this->students['Emil']->student_code, $captured);
    }
}
