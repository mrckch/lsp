<?php

declare(strict_types=1);

namespace App\Filament\Resources\TestRunResource\Widgets;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Permission\ScopeFilter;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

/**
 * LQ-Verteilung eines Testdurchlaufs als Boxplot (Tukey-Whisker, 1,5 × IQR)
 * mit allen Einzelwerten als Punkte; nur Schüler im Scope des Users.
 * Wird serverseitig als SVG gerendert und pollt wie die Kennzahlen.
 */
class TestRunLqBoxplot extends Widget
{
    /** LQ-Grenze „auffällig“ (vgl. DefaultSupportThresholdsSeeder) */
    public const THRESHOLD = 85;

    public const NORM_MEAN = 100;

    public const GENDER_LABELS = ['w' => 'Mädchen', 'm' => 'Jungen', 'other' => 'divers/unbekannt'];

    public ?Model $record = null;

    /** Punkte nach Geschlecht einfärben (per Schalter, bleibt beim Pollen erhalten) */
    public bool $byGender = false;

    protected static string $view = 'filament.resources.test-run.lq-boxplot';

    protected static ?string $pollingInterval = '10s';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{n: int, values: list<array{lq: int, gender: string, label: string}>, stats: ?array{min: float, q1: float, median: float, q3: float, max: float, lo: float, hi: float, below: int}, domain: array{0: int, 1: int}, groups: array<string, array{n: int, median: float}>}
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
                        .' (Rohwert '.$a->score_raw.', '.self::GENDER_LABELS[$gender].')',
                ];
            }
        }

        usort($values, fn ($a, $b) => $a['lq'] <=> $b['lq']);
        $lqs = array_column($values, 'lq');

        // Kennzahlen je Geschlecht für die Legende
        $groups = [];
        foreach (array_keys(self::GENDER_LABELS) as $g) {
            $groupLqs = array_column(array_filter($values, fn ($v) => $v['gender'] === $g), 'lq');
            if ($groupLqs !== []) {
                $groups[$g] = ['n' => count($groupLqs), 'median' => self::quantile(array_values($groupLqs), 0.5)];
            }
        }

        return [
            'n' => count($lqs),
            'values' => $values,
            'stats' => $lqs === [] ? null : self::stats($lqs),
            'domain' => self::domain($lqs),
            'groups' => $groups,
        ];
    }

    /**
     * Fünf-Punkte-Zusammenfassung + Tukey-Whisker.
     *
     * @param  list<int>  $sorted  aufsteigend sortiert
     * @return array{min: float, q1: float, median: float, q3: float, max: float, lo: float, hi: float, below: int}
     */
    public static function stats(array $sorted): array
    {
        $q1 = self::quantile($sorted, 0.25);
        $q3 = self::quantile($sorted, 0.75);
        $iqr = $q3 - $q1;
        $inside = array_values(array_filter($sorted, fn ($v) => $v >= $q1 - 1.5 * $iqr && $v <= $q3 + 1.5 * $iqr));

        return [
            'min' => (float) $sorted[0],
            'q1' => $q1,
            'median' => self::quantile($sorted, 0.5),
            'q3' => $q3,
            'max' => (float) $sorted[count($sorted) - 1],
            'lo' => (float) ($inside[0] ?? $sorted[0]),
            'hi' => (float) ($inside[count($inside) - 1] ?? $sorted[count($sorted) - 1]),
            'below' => count(array_filter($sorted, fn ($v) => $v < self::THRESHOLD)),
        ];
    }

    /**
     * Quantil mit linearer Interpolation (wie Excel QUARTIL.INKL / R type 7).
     *
     * @param  list<int>  $sorted
     */
    public static function quantile(array $sorted, float $p): float
    {
        $pos = (count($sorted) - 1) * $p;
        $lower = (int) floor($pos);
        $upper = (int) ceil($pos);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($pos - $lower);
    }

    /**
     * Achsenbereich: mindestens 70–130, auf Zehner gerundet.
     *
     * @param  list<int>  $lqs
     * @return array{0: int, 1: int}
     */
    private static function domain(array $lqs): array
    {
        $min = min([70, ...$lqs]);
        $max = max([130, ...$lqs]);

        return [(int) (floor(($min - 5) / 10) * 10), (int) (ceil(($max + 5) / 10) * 10)];
    }
}
