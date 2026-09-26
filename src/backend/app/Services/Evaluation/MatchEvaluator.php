<?php

namespace App\Services\Evaluation;

use App\Models\Candidate;
use App\Models\MatchRun;

/**
 * Scores a match run against the hidden truth.
 *
 * EVALUATION ONLY — see GroundTruth. The point of measuring is to be able to
 * say what the system is worth, so the metrics here are chosen to be hard on
 * it: ranking accuracy, but also whether it knows when to say nothing, and
 * whether it ever loses a true pair entirely.
 *
 * The dataset ships a naive baseline measured on the gold normalisation
 * (Recall@1 72/86, Recall@3 82/86, one-to-one assignment 73/86, and a best
 * candidate offered for every one of the twelve bodies that has no partner).
 * Comparisons against it are only fair on the same input, which is why the
 * report names the extractor every figure came from.
 */
class MatchEvaluator
{
    /** The published naive-baseline figures, for side-by-side reporting. */
    public const BASELINE = [
        'recall_at_1' => 72,
        'recall_at_3' => 82,
        'assignment' => 73,
        'true_pairs' => 86,
        'refusals_correct' => 0,
        'refusal_total' => 12,
        'false_exclusions' => 0,
    ];

    public function __construct(
        protected GroundTruth $truth,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(MatchRun $run, ?string $split = null): array
    {
        $truePairs = $this->truth->truePairs($split);
        $noMatchBodies = $this->truth->bodiesWithNoMatch($split);
        $meta = $this->truth->caseMeta();

        $candidates = Candidate::where('run_id', $run->run_id)
            ->orderBy('rank')
            ->get()
            ->groupBy('pm_id');

        $rank1 = 0;
        $rank3 = 0;
        $falseExclusions = 0;
        $assignmentCorrect = 0;
        $assignmentMade = 0;
        $falseRefusals = 0;
        $perCase = [];
        $perTag = [];
        $misses = [];

        foreach ($truePairs as $pmId => $trueAmId) {
            $list = ($candidates[$pmId] ?? collect())->values();
            $ranked = $list->pluck('am_id')->all();

            $position = array_search($trueAmId, $ranked, true);
            $inTop1 = $position === 0;
            $inTop3 = $position !== false && $position < 3;

            $rank1 += $inTop1 ? 1 : 0;
            $rank3 += $inTop3 ? 1 : 0;

            // The true partner never appeared at all — the one failure a DVI
            // tool must not have, because nothing downstream can recover it.
            if ($position === false) {
                $falseExclusions++;
            }

            /*
             * A body that does have a partner, which the system nonetheless
             * declares hopeless. This is the cost of refusing, and it has to
             * be reported beside the refusal rate — otherwise a system could
             * score a perfect twelve out of twelve simply by refusing
             * everything, and the refusal metric would be meaningless.
             */
            if ($list->isEmpty() || $list->every(fn ($c) => $c->confidence_band === Candidate::BAND_NONE)) {
                $falseRefusals++;
            }

            $assigned = $list->firstWhere('assigned', true);

            if ($assigned) {
                $assignmentMade++;
                $assignmentCorrect += $assigned->am_id === $trueAmId ? 1 : 0;
            }

            $caseType = $meta[$pmId]['case_type'] ?? 'unknown';
            $this->tally($perCase, $caseType, $inTop1, $inTop3);

            foreach ($meta[$pmId]['tags'] ?? [] as $tag) {
                $this->tally($perTag, $tag, $inTop1, $inTop3);
            }

            if (! $inTop1) {
                $misses[] = [
                    'pm_id' => $pmId,
                    'expected' => $trueAmId,
                    'got' => $ranked[0] ?? null,
                    'true_rank' => $position === false ? null : $position + 1,
                    'case_type' => $caseType,
                    'tags' => implode(',', $meta[$pmId]['tags'] ?? []),
                ];
            }
        }

        /*
         * Refusal. Twelve bodies have no ante-mortem partner anywhere in the
         * dataset, and for each the only correct output is that there is no
         * credible candidate. The published baseline scores zero here: it
         * always returns its best guess. Offering a family the wrong body is
         * not a smaller error than offering none.
         */
        $refusalsCorrect = 0;

        foreach ($noMatchBodies as $pmId) {
            $list = $candidates[$pmId] ?? collect();

            if ($list->isEmpty() || $list->every(fn ($c) => $c->confidence_band === Candidate::BAND_NONE)) {
                $refusalsCorrect++;
            }
        }

        $total = count($truePairs);

        return [
            'run_id' => $run->run_id,
            'split' => $split ?? 'all',
            'extractor_version' => $run->extractor_version,
            'scorer_version' => $run->scorer_version,
            'true_pairs' => $total,
            'recall_at_1' => $rank1,
            'recall_at_3' => $rank3,
            'recall_at_1_pct' => $total > 0 ? round($rank1 / $total * 100, 1) : 0.0,
            'recall_at_3_pct' => $total > 0 ? round($rank3 / $total * 100, 1) : 0.0,
            'false_exclusions' => $falseExclusions,
            'assignment_correct' => $assignmentCorrect,
            'assignment_made' => $assignmentMade,
            'refusals_correct' => $refusalsCorrect,
            'false_refusals' => $falseRefusals,
            'refusal_total' => count($noMatchBodies),
            'per_case_type' => $perCase,
            'per_tag' => $perTag,
            'misses' => $misses,
        ];
    }

    /**
     * @param  array<string, array{n: int, top1: int, top3: int}>  $bucket
     */
    protected function tally(array &$bucket, string $key, bool $inTop1, bool $inTop3): void
    {
        $bucket[$key] ??= ['n' => 0, 'top1' => 0, 'top3' => 0];
        $bucket[$key]['n']++;
        $bucket[$key]['top1'] += $inTop1 ? 1 : 0;
        $bucket[$key]['top3'] += $inTop3 ? 1 : 0;
    }
}
