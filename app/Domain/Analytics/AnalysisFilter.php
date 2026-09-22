<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\School\Models\LearningGroup;
use App\Domain\School\Models\SchoolYear;
use App\Domain\TestRun\Models\AssessmentType;
use App\Domain\TestRun\Models\TestRun;
use Illuminate\Support\Carbon;

/**
 * Filter- und Gruppierungseinstellungen der Datenanalyse.
 * Serialisierbar (Livewire-Form, URL, später gespeicherte Auswertungen).
 */
final readonly class AnalysisFilter
{
    public const GROUP_BY = [
        'none' => 'keine (alle zusammen)',
        'learning_group' => 'Lerngruppe/Klasse',
        'gender' => 'Geschlecht',
        'grade_level' => 'Jahrgang',
        'test_run' => 'Testdurchlauf',
        'assessment_type' => 'Erhebungstyp',
        'parallel_form' => 'Parallelform',
    ];

    public const GENDERS = ['w' => 'Mädchen', 'm' => 'Jungen', 'other' => 'divers/unbekannt'];

    public const BASIS = [
        'latest_per_student' => 'nur letzter Versuch je Schüler',
        'all_attempts' => 'alle gewerteten Versuche',
    ];

    /**
     * @param  list<int>  $assessmentTypeIds
     * @param  list<int>  $testRunIds
     * @param  list<string>  $gradeLevels
     * @param  list<int>  $learningGroupIds
     * @param  list<string>  $genders  w | m | other
     * @param  list<string>  $parallelForms
     */
    public function __construct(
        public ?int $schoolYearId = null,
        public array $assessmentTypeIds = [],
        public array $testRunIds = [],
        public array $gradeLevels = [],
        public array $learningGroupIds = [],
        public array $genders = [],
        public ?bool $repeater = null,
        public array $parallelForms = [],
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public string $groupBy = 'learning_group',
        public ?string $secondaryGroupBy = null,
        public string $basis = 'latest_per_student',
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $ints = fn (string $k) => array_values(array_map('intval', array_filter((array) ($data[$k] ?? []), fn ($v) => $v !== '' && $v !== null)));
        $strings = fn (string $k) => array_values(array_map('strval', array_filter((array) ($data[$k] ?? []), fn ($v) => $v !== '' && $v !== null)));

        $groupBy = (string) ($data['group_by'] ?? 'learning_group');
        $secondary = $data['secondary_group_by'] ?? null;
        $repeater = $data['repeater'] ?? null;
        if ($groupBy === 'none' && is_string($secondary) && $secondary !== 'none') {
            [$groupBy, $secondary] = [$secondary, null];
        }

        return new self(
            schoolYearId: filled($data['school_year_id'] ?? null) ? (int) $data['school_year_id'] : null,
            assessmentTypeIds: $ints('assessment_type_ids'),
            testRunIds: $ints('test_run_ids'),
            gradeLevels: $strings('grade_levels'),
            learningGroupIds: $ints('learning_group_ids'),
            genders: array_values(array_intersect($strings('genders'), array_keys(self::GENDERS))),
            repeater: $repeater === null || $repeater === '' ? null : filter_var($repeater, FILTER_VALIDATE_BOOL),
            parallelForms: $strings('parallel_forms'),
            dateFrom: filled($data['date_from'] ?? null) ? (string) $data['date_from'] : null,
            dateTo: filled($data['date_to'] ?? null) ? (string) $data['date_to'] : null,
            groupBy: array_key_exists($groupBy, self::GROUP_BY) ? $groupBy : 'learning_group',
            secondaryGroupBy: is_string($secondary) && $secondary !== $groupBy && $secondary !== 'none' && array_key_exists($secondary, self::GROUP_BY)
                ? $secondary : null,
            basis: array_key_exists((string) ($data['basis'] ?? ''), self::BASIS) ? (string) $data['basis'] : 'latest_per_student',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'school_year_id' => $this->schoolYearId,
            'assessment_type_ids' => $this->assessmentTypeIds,
            'test_run_ids' => $this->testRunIds,
            'grade_levels' => $this->gradeLevels,
            'learning_group_ids' => $this->learningGroupIds,
            'genders' => $this->genders,
            'repeater' => $this->repeater === null ? null : ($this->repeater ? '1' : '0'),
            'parallel_forms' => $this->parallelForms,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'group_by' => $this->groupBy,
            'secondary_group_by' => $this->secondaryGroupBy,
            'basis' => $this->basis,
        ];
    }

    /**
     * Menschenlesbare Beschreibung (PDF-Kopf, Audit).
     */
    public function describe(): string
    {
        $parts = [];
        if ($this->schoolYearId !== null) {
            $parts[] = 'Schuljahr '.(SchoolYear::query()->whereKey($this->schoolYearId)->value('label') ?? '?');
        }
        if ($this->assessmentTypeIds !== []) {
            $parts[] = 'Erhebung: '.AssessmentType::query()->whereIn('id', $this->assessmentTypeIds)->orderBy('sort_order')->pluck('label')->implode(', ');
        }
        if ($this->testRunIds !== []) {
            $parts[] = 'Testdurchläufe: '.TestRun::query()->whereIn('id', $this->testRunIds)->orderBy('name')->pluck('name')->implode(', ');
        }
        if ($this->gradeLevels !== []) {
            $parts[] = 'Jahrgang '.implode(', ', $this->gradeLevels);
        }
        if ($this->learningGroupIds !== []) {
            $parts[] = 'Lerngruppen: '.LearningGroup::query()->whereIn('id', $this->learningGroupIds)->orderBy('name')->pluck('name')->implode(', ');
        }
        if ($this->genders !== []) {
            $parts[] = implode(' + ', array_map(fn ($g) => self::GENDERS[$g], $this->genders));
        }
        if ($this->repeater !== null) {
            $parts[] = $this->repeater ? 'nur Wiederholer' : 'ohne Wiederholer';
        }
        if ($this->parallelForms !== []) {
            $parts[] = 'Parallelform '.implode(', ', $this->parallelForms);
        }
        if ($this->dateFrom !== null || $this->dateTo !== null) {
            $fmt = fn (?string $d) => $d === null ? '…' : Carbon::parse($d)->format('d.m.Y');
            $parts[] = 'Zeitraum '.$fmt($this->dateFrom).' – '.$fmt($this->dateTo);
        }
        if ($parts === []) {
            $parts[] = 'alle Daten';
        }

        $grouping = 'gruppiert nach '.self::GROUP_BY[$this->groupBy];
        if ($this->groupBy === 'none') {
            $grouping = 'ohne Gruppierung';
        }
        if ($this->secondaryGroupBy !== null) {
            $grouping .= ' × '.self::GROUP_BY[$this->secondaryGroupBy];
        }

        return implode(' · ', $parts).' · '.$grouping.' · '.self::BASIS[$this->basis];
    }
}
