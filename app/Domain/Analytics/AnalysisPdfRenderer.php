<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * HTML für den A4-Export der Datenanalyse (Gotenberg rendert daraus das PDF).
 * Die Schülerlisten enthalten Klarnamen → nur synchron im Request mit entsperrter Session aufrufen.
 */
final class AnalysisPdfRenderer
{
    public function __construct(private readonly AnalysisReport $report) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{show_bands?: bool, by_gender?: bool}  $display
     */
    public function html(
        AnalysisFilter $filter,
        Collection $rows,
        User $user,
        string $orientation = 'portrait',
        bool $withStudentLists = true,
        array $display = [],
    ): string {
        $dist = $this->report->groupedDistribution($rows, $filter->groupBy, $filter->secondaryGroupBy);

        return view('print.analysis', [
            'schoolName' => AppSetting::singleton()->school_name ?? 'Schule',
            'filterText' => $filter->describe(),
            'createdBy' => $user->display_name ?? $user->username,
            'createdAt' => now()->format('d.m.Y, H:i'),
            'orientation' => $orientation === 'landscape' ? 'landscape' : 'portrait',
            'dist' => $dist,
            'kpis' => self::kpis($rows, $dist),
            'studentLists' => $withStudentLists
                ? array_map(fn (array $g) => ['label' => trim($g['label'].($g['sublabel'] ? ' · '.$g['sublabel'] : '')), 'students' => AnalysisReport::studentList($g['rows'])], $dist['groups'])
                : [],
            'showBands' => (bool) ($display['show_bands'] ?? true),
            'byGender' => (bool) ($display['by_gender'] ?? false),
            'genderInfo' => self::genderInfo($rows),
        ])->render();
    }

    /**
     * Fußzeile je Seite (Gotenberg footer.html; pageNumber/totalPages werden eingesetzt).
     */
    public function footer(bool $includesClearnames): string
    {
        $school = e(AppSetting::singleton()->school_name ?? '');
        $note = $includesClearnames ? 'Vertraulich – enthält Klarnamen' : 'Vertraulich';

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
body { font-family: system-ui, "Segoe UI", Roboto, Arial, sans-serif; font-size: 8pt; color: #555; margin: 0 14mm; width: 100%; }
.row { display: flex; justify-content: space-between; }
strong { color: #b91c1c; }
</style></head><body><div class="row">
<span>{$school} · Datenanalyse Lese-Screening</span>
<strong>{$note}</strong>
<span>Seite <span class="pageNumber"></span> / <span class="totalPages"></span></span>
</div></body></html>
HTML;
    }

    /**
     * Kopfkennzahlen: SuS, Median, Anteile je Förderbereich.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $dist
     * @return array{students: int, attempts: int, median: ?float, shares: list<array{label: string, severity: string, count: int, pct: int}>}
     */
    public static function kpis(Collection $rows, array $dist): array
    {
        $n = $rows->count();

        return [
            'students' => $rows->pluck('student_id')->unique()->count(),
            'attempts' => $n,
            'median' => $dist['total']['summary']['median'] ?? null,
            'shares' => collect($dist['bands'])->where('severity', '!=', 'none')->map(fn (array $b) => [
                'label' => $b['label'],
                'severity' => $b['severity'],
                'count' => $b['count'],
                'pct' => $n > 0 ? (int) round($b['count'] / $n * 100) : 0,
            ])->values()->all(),
        ];
    }

    /**
     * n und Median je Geschlecht (Legende „Nach Geschlecht“).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array{n: int, median: float}>
     */
    public static function genderInfo(Collection $rows): array
    {
        $info = [];
        foreach (array_keys(DistributionStats::GENDER_LABELS) as $g) {
            $lqs = $rows->where('gender', $g)->pluck('lq')->sort()->values()->all();
            if ($lqs !== []) {
                $info[$g] = ['n' => count($lqs), 'median' => DistributionStats::quantile($lqs, 0.5)];
            }
        }

        return $info;
    }
}
