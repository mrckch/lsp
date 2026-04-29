<?php

declare(strict_types=1);

namespace App\Domain\Permission;

use App\Domain\Student\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wendet die User-Scope-Einschränkung (auf Lerngruppen) auf Queries an.
 */
final class ScopeFilter
{
    public function __construct(private readonly PermissionResolver $resolver) {}

    /**
     * Filtert eine Student-Query auf die Lerngruppen, die der User sehen darf.
     */
    public function applyToStudents(Builder $query, User $user): Builder
    {
        $scopes = $this->resolver->scopeLearningGroupIds($user);

        if ($scopes === null) {
            return $query;
        }

        if (empty($scopes)) {
            return $query->whereRaw('1=0');
        }

        return $query->whereExists(function ($q) use ($scopes) {
            $q->selectRaw('1')
                ->from('student_group_memberships')
                ->whereColumn('student_group_memberships.student_id', 'students.id')
                ->whereIn('student_group_memberships.learning_group_id', $scopes);
        });
    }

    /**
     * Filtert eine LearningGroup-Query auf die zugewiesenen Gruppen.
     */
    public function applyToLearningGroups(Builder $query, User $user): Builder
    {
        $scopes = $this->resolver->scopeLearningGroupIds($user);
        if ($scopes === null) {
            return $query;
        }
        if (empty($scopes)) {
            return $query->whereRaw('1=0');
        }

        return $query->whereIn('id', $scopes);
    }
}
