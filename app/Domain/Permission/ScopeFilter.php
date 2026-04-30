<?php

declare(strict_types=1);

namespace App\Domain\Permission;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wendet die User-Scope-Einschränkung (auf Lerngruppen) auf Queries an.
 *
 * Konvention:
 *   - scopes === null  → User ist ungescoped (sieht alles)
 *   - scopes === []    → leerer Scope (sieht nichts)
 *   - scopes === [...] → eingeschränkt auf genau diese learning_group_ids
 */
final class ScopeFilter
{
    public function __construct(private readonly PermissionResolver $resolver) {}

    /**
     * Liefert null (ungescoped) oder ein Array mit erlaubten learning_group_ids.
     *
     * @return array<int>|null
     */
    public function scopesFor(User $user): ?array
    {
        return $this->resolver->scopeLearningGroupIds($user);
    }

    /**
     * Bequemer Helper: prüft ob User auf eine konkrete Lerngruppe darf.
     */
    public function canSeeLearningGroup(User $user, int $learningGroupId): bool
    {
        $scopes = $this->scopesFor($user);
        if ($scopes === null) {
            return true;
        }

        return in_array($learningGroupId, $scopes, true);
    }

    /**
     * Filtert eine Student-Query auf die Lerngruppen, die der User sehen darf.
     */
    public function applyToStudents(Builder $query, User $user): Builder
    {
        return $this->whenScoped($query, $user, function (Builder $q, array $scopes) {
            return $q->whereExists(function ($sub) use ($scopes) {
                $sub->selectRaw('1')
                    ->from('student_group_memberships')
                    ->whereColumn('student_group_memberships.student_id', 'students.id')
                    ->whereIn('student_group_memberships.learning_group_id', $scopes);
            });
        });
    }

    /**
     * Filtert eine LearningGroup-Query auf die zugewiesenen Gruppen.
     */
    public function applyToLearningGroups(Builder $query, User $user): Builder
    {
        return $this->whenScoped($query, $user, fn (Builder $q, array $scopes) => $q->whereIn('learning_groups.id', $scopes));
    }

    /**
     * Filtert eine TestRun-Query: ein Run ist sichtbar, wenn er mit mind. einer
     * scope-Lerngruppe verknüpft ist.
     */
    public function applyToTestRuns(Builder $query, User $user): Builder
    {
        return $this->whenScoped($query, $user, function (Builder $q, array $scopes) {
            return $q->whereExists(function ($sub) use ($scopes) {
                $sub->selectRaw('1')
                    ->from('test_run_groups')
                    ->whereColumn('test_run_groups.test_run_id', 'test_runs.id')
                    ->whereIn('test_run_groups.learning_group_id', $scopes);
            });
        });
    }

    /**
     * Filtert eine TestAttempt-Query auf Versuche von SuS in scope-Lerngruppen.
     */
    public function applyToAttempts(Builder $query, User $user): Builder
    {
        return $this->whenScoped($query, $user, function (Builder $q, array $scopes) {
            return $q->whereExists(function ($sub) use ($scopes) {
                $sub->selectRaw('1')
                    ->from('student_group_memberships')
                    ->whereColumn('student_group_memberships.student_id', 'test_attempts.student_id')
                    ->whereIn('student_group_memberships.learning_group_id', $scopes);
            });
        });
    }

    /**
     * Filtert eine StudentLoginCode-Query analog zu attempts.
     */
    public function applyToLoginCodes(Builder $query, User $user): Builder
    {
        return $this->whenScoped($query, $user, function (Builder $q, array $scopes) {
            return $q->whereExists(function ($sub) use ($scopes) {
                $sub->selectRaw('1')
                    ->from('student_group_memberships')
                    ->whereColumn('student_group_memberships.student_id', 'student_login_codes.student_id')
                    ->whereIn('student_group_memberships.learning_group_id', $scopes);
            });
        });
    }

    /**
     * Hilfsmethode: gemeinsame Behandlung der Scope-Sentinel-Werte.
     */
    private function whenScoped(Builder $query, User $user, callable $apply): Builder
    {
        $scopes = $this->scopesFor($user);

        if ($scopes === null) {
            return $query;
        }
        if (empty($scopes)) {
            return $query->whereRaw('1=0');
        }

        return $apply($query, $scopes);
    }
}
