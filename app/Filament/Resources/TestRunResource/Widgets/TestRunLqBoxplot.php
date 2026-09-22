<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestRunResource\Widgets;

use App\Domain\Analytics\DistributionStats;
use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Permission\ScopeFilter;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

/**
 * LQ-Verteilung eines Testdurchlaufs als Boxplot (Tukey-Whisker, 1,5 × IQR)
 * mit allen Einzelwerten als Punkte; nur Schüler im Scope des Users.
 * Wird serverseitig als SVG gerendert (<x-analysis.boxplot>) und pollt wie die Kennzahlen.
 */
class TestRunLqBoxplot extends Widget
{
    public ?Model $record = null;

    /** Punkte nach Geschlecht einfärben (per Schalter, bleibt beim Pollen erhalten) */
    public bool $byGender = false;

    /** Förderbereiche (aus den Förderbedarfsschwellen) einblenden */
    public bool $showBands = false;

    protected static string $view = 'filament.resources.test-run.lq-boxplot';

    protected static ?string $pollingInterval = '10s';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{n: int, values: list<array{lq: int, gender: string, label: string}>, stats: ?array<string, mixed>, domain: array{0: int, 1: int}, groups: array<string, array{n: int, median: float}>, bands: list<array{from: ?int, to: ?int, label: string, severity: string, count: int}>, threshold: int, threshold_label: string}
     */
    public function getData(): array
    {
        $values = [];
        if ($this->record !== null && auth()->user() !== null) {
            $attempts = app(ScopeFilter::class)->applyToAttempts(
                TestAttempt::query()
                    ->with('student')
                    ->where('test_run_id', $this->record->getKey())
                    ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
                    ->whereNotNull('lq_current'),
                auth()->user(),
            )->orderByDesc('id')->get()->unique('student_id'); // letzter gewerteter Versuch je Schüler

            foreach ($attempts as $a) {
                $name = trim(($a->student?->first_name_encrypted ?? '').' '.($a->student?->last_name_encrypted ?? ''));
                $gender = in_array($a->student?->gender, ['w', 'm'], true) ? $a->student->gender : 'other';
                $values[] = [
                    'lq' => (int) $a->lq_current,
                    'gender' => $gender,
                    'label' => ($name !== '' && $name !== '*** ***' ? $name.': ' : '').'LQ '.$a->lq_current
                        .' (Rohwert '.$a->score_raw.', '.DistributionStats::GENDER_LABELS[$gender].')',
                ];
            }
        }

        usort($values, fn ($a, $b) => $a['lq'] <=> $b['lq']);
        $lqs = array_column($values, 'lq');

        // Kennzahlen je Geschlecht für die Legende
        $groups = [];
        foreach (array_keys(DistributionStats::GENDER_LABELS) as $g) {
            $groupLqs = array_column(array_filter($values, fn ($v) => $v['gender'] === $g), 'lq');
            if ($groupLqs !== []) {
                $groups[$g] = ['n' => count($groupLqs), 'median' => DistributionStats::quantile($groupLqs, 0.5)];
            }
        }

        $bands = DistributionStats::bands($lqs);
        [$threshold, $thresholdLabel] = DistributionStats::thresholdOf($bands);

        return [
            'n' => count($lqs),
            'values' => $values,
            'stats' => $lqs === [] ? null : DistributionStats::stats($lqs, $threshold),
            'domain' => DistributionStats::domain($lqs),
            'groups' => $groups,
            'bands' => $bands,
            'threshold' => $threshold,
            'threshold_label' => $thresholdLabel,
        ];
    }

    /**
     * @param  list<int>  $sorted
     * @return array{min: float, q1: float, median: float, q3: float, max: float, lo: float, hi: float, below: int}
     */
    public static function stats(array $sorted): array
    {
        return DistributionStats::stats($sorted);
    }

    /**
     * @param  list<int>  $lqs
     * @return list<array{from: ?int, to: ?int, label: string, severity: string, count: int}>
     */
    public static function bands(array $lqs): array
    {
        return DistributionStats::bands($lqs);
    }
}
