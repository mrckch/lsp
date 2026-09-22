<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Attempt\Models\TestAttempt;
use App\Domain\Permission\ScopeFilter;
use App\Domain\School\Models\LearningGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Liefert die gewerteten Versuche für die Datenanalyse – eine Zeile je Versuch,
 * bereits auf Filter und Sichtbarkeit des Users eingeschränkt.
 *
 * Sichtbarkeit: mit „analytics.school_overview“ die ganze Schule, sonst strikt
 * die zugewiesenen Lerngruppen (ohne Zuweisung: nichts).
 */
final class AnalysisDataset
{
    public const COUNTED_STATUSES = ['abgegeben', 'zeit_abgelaufen'];

    public function __construct(private readonly ScopeFilter $scopeFilter) {}

    /**
     * null = alle Lerngruppen, [] = keine, sonst erlaubte learning_group_ids.
     *
     * @return list<int>|null
     */
    public function allowedGroupIds(User $user): ?array
    {
        if ($user->hasPermission('analytics.school_overview')) {
            return null;
        }

        return array_values($this->scopeFilter->scopesFor($user) ?? []);
    }

    /**
     * @param  bool  $perWave  je Schüler den letzten Versuch JE ERHEBUNG behalten (Entwicklung)
     * @return Collection<int, array<string, mixed>> Felder siehe toRow()
     */
    public function rows(AnalysisFilter $f, User $user, bool $perWave = false): Collection
    {
        $allowed = $this->allowedGroupIds($user);
        if ($allowed === []) {
            return collect();
        }

        $attempts = TestAttempt::query()
            ->with([
                'student.learningGroups',
                'student.enrollments',
                'testRun.learningGroups',
                'testRun.assessmentType',
                'testRun.schoolYear',
            ])
            ->withCount('answers')
            ->whereIn('status', self::COUNTED_STATUSES)
            ->whereNotNull('lq_current')
            ->whereHas('student')
            ->whereHas('testRun', function (Builder $r) use ($f) {
                $r->when($f->schoolYearId !== null, fn (Builder $q) => $q->where('school_year_id', $f->schoolYearId))
                    ->when($f->assessmentTypeIds !== [], fn (Builder $q) => $q->whereIn('assessment_type_id', $f->assessmentTypeIds));
            })
            ->when($f->testRunIds !== [], fn (Builder $q) => $q->whereIn('test_run_id', $f->testRunIds))
            ->when($f->parallelForms !== [], fn (Builder $q) => $q->whereIn('parallel_form', $f->parallelForms))
            ->when($f->dateFrom !== null, fn (Builder $q) => $q->where('submitted_at', '>=', Carbon::parse($f->dateFrom)->startOfDay()))
            ->when($f->dateTo !== null, fn (Builder $q) => $q->where('submitted_at', '<=', Carbon::parse($f->dateTo)->endOfDay()))
            ->when($f->genders !== [], fn (Builder $q) => $q->whereHas('student', function (Builder $s) use ($f) {
                $db = array_diff($f->genders, ['other']);
                $s->where(function (Builder $w) use ($db, $f) {
                    $w->whereIn('gender', $db === [] ? ['-'] : $db);
                    if (in_array('other', $f->genders, true)) {
                        $w->orWhereIn('gender', ['d', 'unbekannt']);
                    }
                });
            }))
            ->when($allowed !== null, fn (Builder $q) => $this->whereMemberOf($q, $allowed))
            ->when($f->learningGroupIds !== [], fn (Builder $q) => $this->whereMemberOf($q, $f->learningGroupIds))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        $rows = $attempts->toBase()
            ->map(fn (TestAttempt $a) => $this->toRow($a, $f, $allowed))
            ->filter(fn (array $r) => $f->gradeLevels === [] || in_array((string) $r['grade_level'], $f->gradeLevels, true))
            ->filter(fn (array $r) => $f->repeater === null || $r['is_repeater'] === $f->repeater)
            ->filter(fn (array $r) => $f->learningGroupIds === [] || in_array($r['learning_group_id'], $f->learningGroupIds, true));

        if ($f->basis === 'latest_per_student') {
            // Versuche sind absteigend sortiert → erster je Schlüssel ist der jüngste.
            // Bei Gruppierung nach Erhebung/Run bleibt je Erhebung ein Versuch erhalten.
            $perRun = array_intersect(['test_run', 'assessment_type'], [$f->groupBy, $f->secondaryGroupBy]);
            $rows = $rows->unique(fn (array $r) => $r['student_id']
                .($perWave ? '|w'.$r['wave_key'] : '')
                .(in_array('test_run', $perRun, true) ? '|r'.$r['test_run_id'] : '')
                .(in_array('assessment_type', $perRun, true) ? '|t'.$r['assessment_type_id'] : ''));
        }

        return $rows->values();
    }

    /**
     * @param  list<int>|null  $allowed
     * @return array<string, mixed>
     */
    private function toRow(TestAttempt $a, AnalysisFilter $f, ?array $allowed): array
    {
        $student = $a->student;
        $run = $a->testRun;
        $group = $this->chooseGroup(
            $student->learningGroups->where('school_year_id', $run->school_year_id)->values()->all()
                ?: $student->learningGroups->all(),
            [$f->learningGroupIds ?: null, $allowed, $run->learningGroups->pluck('id')->all()],
        );
        $enrollment = $student->enrollments->firstWhere('school_year_id', $run->school_year_id);
        $name = trim(($student->first_name_encrypted ?? '').' '.($student->last_name_encrypted ?? ''));
        // Erhebungswelle: Erhebungstyp im Schuljahr (z. B. „Herbst 2026/27“), ohne Typ der einzelne Run
        $hasType = $run->assessment_type_id !== null;

        return [
            'attempt_id' => $a->id,
            'student_id' => $student->id,
            'student_code' => (string) $student->student_code,
            'name' => $name,
            'gender' => in_array($student->gender, ['w', 'm'], true) ? $student->gender : 'other',
            'learning_group_id' => $group?->id,
            'learning_group_name' => $group->name ?? 'ohne Lerngruppe',
            'grade_level' => $group->grade_level ?? $enrollment?->grade_level,
            'is_repeater' => (bool) ($enrollment->is_repeater ?? false),
            'test_run_id' => $run->id,
            'test_run_name' => $run->name,
            'test_run_date' => $run->scheduled_for?->toDateString(),
            'assessment_type_id' => $run->assessment_type_id,
            'assessment_type' => $run->assessmentType->label ?? 'ohne Erhebungstyp',
            'assessment_type_sort' => (int) ($run->assessmentType->sort_order ?? PHP_INT_MAX),
            'school_year_id' => $run->school_year_id,
            'wave_key' => $hasType ? 'y'.$run->school_year_id.'t'.$run->assessment_type_id : 'r'.$run->id,
            'wave_label' => $hasType
                ? trim(($run->assessmentType->label ?? 'Erhebung').' '.($run->schoolYear->label ?? ''))
                : $run->name,
            'wave_sort' => ($run->schoolYear?->start_date?->format('Y-m-d') ?? '0000').'|'
                .str_pad((string) min((int) ($run->assessmentType->sort_order ?? 99999), 99999), 5, '0', STR_PAD_LEFT).'|'
                .($run->scheduled_for?->toDateString() ?? ($a->submitted_at?->toDateString() ?? '')),
            'parallel_form' => $a->parallel_form,
            'lq' => (int) $a->lq_current,
            'raw' => (int) $a->score_raw,
            'answered_count' => (int) $a->answers_count,
            'submitted_at' => $a->submitted_at,
        ];
    }

    /**
     * Wählt die Lerngruppe, unter der ein Versuch gezählt wird: schrittweise auf
     * die bevorzugten Mengen eingeengt (Filter → Scope → Run-Gruppen), dann Klasse vor Kurs.
     *
     * @param  list<LearningGroup>  $groups
     * @param  list<list<int>|null>  $preferSets
     */
    private function chooseGroup(array $groups, array $preferSets): ?LearningGroup
    {
        foreach ($preferSets as $ids) {
            if ($ids === null) {
                continue;
            }
            $narrowed = array_values(array_filter($groups, fn (LearningGroup $g) => in_array($g->id, $ids, true)));
            if ($narrowed !== []) {
                $groups = $narrowed;
            }
        }
        usort($groups, fn (LearningGroup $a, LearningGroup $b) => [$a->group_type !== 'klasse', $a->name] <=> [$b->group_type !== 'klasse', $b->name]);

        return $groups[0] ?? null;
    }

    /** @param  list<int>  $groupIds */
    private function whereMemberOf(Builder $q, array $groupIds): Builder
    {
        return $q->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('student_group_memberships')
            ->whereColumn('student_group_memberships.student_id', 'test_attempts.student_id')
            ->whereIn('student_group_memberships.learning_group_id', $groupIds));
    }
}
