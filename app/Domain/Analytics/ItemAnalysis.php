<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Questionnaire\Models\Questionnaire;
use App\Domain\Questionnaire\Models\QuestionnaireQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satzanalyse: je Satz eines Fragebogens Lösungsquote, übersprungen und „nicht erreicht“.
 * „Erreicht“ = bis zum letzten beantworteten Satz des Versuchs gekommen (Tempotest).
 */
final class ItemAnalysis
{
    /** Sätze mit geringerer Lösungsquote gelten als „schwierig“ */
    public const HARD_BELOW_PCT = 70;

    /**
     * Fragebögen der Zeilen mit Anzahl Versuchen, häufigster zuerst.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, string> id => „Name (n Versuche)“
     */
    public function questionnaireOptions(Collection $rows): array
    {
        $counts = $rows->countBy('questionnaire_id')->filter(fn ($c, $id) => $id !== '')->sortDesc();   // Versuche ohne Fragebogen (Schlüssel '') ignorieren
        $names = Questionnaire::query()->whereIn('id', $counts->keys())->pluck('name', 'id');

        return $counts->mapWithKeys(fn (int $c, int|string $id) => [(int) $id => ($names[$id] ?? 'Fragebogen '.$id).' ('.$c.' Versuche)'])->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  AnalysisDataset::rows()
     * @return array<string, mixed>
     */
    public function analyse(Collection $rows, ?int $questionnaireId = null): array
    {
        $options = $this->questionnaireOptions($rows);
        if ($options === []) {
            return ['options' => [], 'questionnaire_id' => null, 'items' => [], 'attempts' => 0];
        }
        $qid = $questionnaireId !== null && array_key_exists($questionnaireId, $options) ? $questionnaireId : (int) array_key_first($options);
        $attemptIds = $rows->where('questionnaire_id', $qid)->pluck('attempt_id')->all();

        $questions = QuestionnaireQuestion::query()
            ->where('questionnaire_id', $qid)
            ->orderBy('sort_order')
            ->get(['id', 'sort_order', 'question_text', 'correct_answer']);

        // Antworten je Versuch: beantwortete Sätze + weitester erreichter Satz
        $answers = collect();
        foreach (array_chunk($attemptIds, 500) as $chunk) {
            $answers = $answers->concat(DB::table('attempt_answers')
                ->join('questionnaire_questions as q', 'q.id', '=', 'attempt_answers.question_id')
                ->whereIn('attempt_answers.test_attempt_id', $chunk)
                ->get(['attempt_answers.test_attempt_id', 'attempt_answers.question_id', 'attempt_answers.is_correct', 'q.sort_order']));
        }
        $reachedUpTo = $answers->groupBy('test_attempt_id')->map(fn (Collection $a) => (int) $a->max('sort_order'));
        $byQuestion = $answers->groupBy('question_id');
        $n = count($attemptIds);

        $items = $questions->values()->map(function (QuestionnaireQuestion $q, int $i) use ($byQuestion, $reachedUpTo, $n) {
            $given = $byQuestion->get($q->id, collect());
            $answered = $given->count();
            $correct = $given->filter(fn ($a) => (bool) $a->is_correct)->count();
            $reached = $reachedUpTo->filter(fn (int $max) => $max >= $q->sort_order)->count();

            return [
                'nr' => $i + 1,
                'question_id' => $q->id,
                'text' => (string) $q->question_text,
                'correct_answer' => (string) $q->correct_answer,
                'answered' => $answered,
                'correct' => $correct,
                'solution_pct' => $answered > 0 ? (float) ($correct / $answered * 100) : null,
                'skipped' => max(0, $reached - $answered),
                'reached_pct' => $n > 0 ? (float) ($reached / $n * 100) : 0.0,
                'hard' => $answered >= AnalysisReport::MIN_GROUP_SIZE && $correct / max(1, $answered) * 100 < self::HARD_BELOW_PCT,
            ];
        })->all();

        return [
            'options' => $options,
            'questionnaire_id' => $qid,
            'questionnaire_name' => Questionnaire::query()->whereKey($qid)->value('name'),
            'attempts' => $n,
            'items' => $items,
            // Satz, bis zu dem die Hälfte der Schüler:innen gekommen ist (Tempo-Kennzahl)
            'median_reached' => $this->medianReachedNr($reachedUpTo, $questions),
            'hard_count' => count(array_filter($items, fn ($it) => $it['hard'])),
        ];
    }

    /**
     * Laufende Satznummer (1 …), die mindestens die Hälfte der Versuche erreicht hat.
     *
     * @param  Collection<int|string, int>  $reachedUpTo  sort_order je Versuch
     * @param  Collection<int, QuestionnaireQuestion>  $questions
     */
    private function medianReachedNr(Collection $reachedUpTo, Collection $questions): ?int
    {
        if ($reachedUpTo->isEmpty()) {
            return null;
        }
        $median = DistributionStats::quantile($reachedUpTo->sort()->values()->all(), 0.5);
        $nr = null;
        foreach ($questions->values() as $i => $q) {
            if ($q->sort_order <= $median) {
                $nr = $i + 1;
            }
        }

        return $nr;
    }
}
