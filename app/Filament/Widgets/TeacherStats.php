<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Permission\PermissionResolver;
use App\Domain\Permission\ScopeFilter;
use App\Domain\School\Models\LearningGroup;
use App\Domain\Student\Models\Student;
use App\Domain\TestRun\Models\TestRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Persönliche Übersicht für Lehrkräfte:
 *  - eigene Lerngruppen (im Scope, oder alle wenn ungescoped)
 *  - aktive Schüler im Scope
 *  - aktive Test-Runs in den eigenen Lerngruppen
 *  - durchschnittlicher LQ des letzten Test-Runs in den eigenen Lerngruppen
 *
 * Sichtbar für jeden eingeloggten User mit students.view (also normalerweise alle).
 * Admin/Schulleitung sehen die globalen Werte (kein Scope).
 */
class TeacherStats extends StatsOverviewWidget
{
    protected static ?int $sort = 60;
    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $u = auth()->user();
        if ($u === null) {
            return false;
        }

        return app(PermissionResolver::class)->can($u, 'students.view');
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $scopeFilter = app(ScopeFilter::class);
        $scopes = $scopeFilter->scopesFor($user);

        // Lerngruppen im Scope
        $groupCount = $scopeFilter->applyToLearningGroups(LearningGroup::query(), $user)->count();

        // Aktive SuS im Scope
        $studentCount = $scopeFilter->applyToStudents(
            Student::query()->where('status', 'aktiv'),
            $user,
        )->count();

        // Aktive TestRuns im Scope
        $activeRuns = $scopeFilter->applyToTestRuns(
            TestRun::query()->where('status', 'aktiv'),
            $user,
        )->count();

        // Letzter abgeschlossener TestRun im Scope + dessen LQ-Durchschnitt
        $lastRunQ = TestRun::query()->whereIn('status', ['aktiv', 'abgeschlossen']);
        $lastRunQ = $scopeFilter->applyToTestRuns($lastRunQ, $user);
        $lastRun = $lastRunQ->latest('updated_at')->first();
        $avgLq = null;
        if ($lastRun !== null) {
            $attemptsQ = TestAttempt::query()
                ->where('test_run_id', $lastRun->id)
                ->whereIn('status', ['abgegeben', 'zeit_abgelaufen'])
                ->whereNotNull('lq_current');
            $attemptsQ = $scopeFilter->applyToAttempts($attemptsQ, $user);
            $avg = $attemptsQ->avg('lq_current');
            $avgLq = $avg !== null ? (int) round((float) $avg) : null;
        }

        $scopeLabel = $scopes === null ? 'alle Lerngruppen' : count($scopes).' zugewiesene Gruppen';

        return [
            Stat::make('Lerngruppen', (string) $groupCount)
                ->description($scopeLabel)
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary'),

            Stat::make('Aktive Schüler/innen', (string) $studentCount)
                ->description('im sichtbaren Bereich')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('primary'),

            Stat::make('Aktive Erhebungen', (string) $activeRuns)
                ->description('Status = aktiv')
                ->descriptionIcon('heroicon-m-play-circle')
                ->color($activeRuns > 0 ? 'success' : 'gray'),

            Stat::make(
                'Ø LQ letzte Erhebung',
                $avgLq !== null ? (string) $avgLq : '–',
            )
                ->description($lastRun?->name ?? 'keine Erhebung gefunden')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($avgLq === null ? 'gray' : ($avgLq < 85 ? 'warning' : 'success')),
        ];
    }
}
