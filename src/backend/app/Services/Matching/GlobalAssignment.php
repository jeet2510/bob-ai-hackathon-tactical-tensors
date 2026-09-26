<?php

namespace App\Services\Matching;

use App\Models\Candidate;
use App\Models\MatchRun;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the incident as a whole rather than one body at a time.
 *
 * Scoring each body independently produces contradictions: two near-identical
 * people, of the same age and build, wearing similar clothes, will both rank
 * first against the same ante-mortem file — and one of those two answers is
 * certainly wrong. Because a person can be in exactly one place, the correct
 * reading is the set of pairings that maximises total evidence across the
 * whole incident under a one-to-one constraint.
 *
 * That is an assignment problem, solved here with the Hungarian algorithm.
 * Its practical effect is on the look-alike clusters the dataset is built
 * around: a slightly weaker pairing is preferred when it frees a much stronger
 * one elsewhere.
 *
 * The result is advisory. It marks which pairing the incident-wide view
 * prefers; it never overrides a reviewer, and the per-body ranking stays
 * visible alongside it so a coordinator can see where the two disagree.
 */
class GlobalAssignment
{
    /**
     * @return int number of bodies assigned
     */
    public function apply(MatchRun $run): int
    {
        $candidates = Candidate::where('run_id', $run->run_id)->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $pmIds = $candidates->pluck('pm_id')->unique()->values()->all();
        $amIds = $candidates->pluck('am_id')->unique()->values()->all();

        $pmIndex = array_flip($pmIds);
        $amIndex = array_flip($amIds);

        $scores = [];

        foreach ($candidates as $candidate) {
            $scores[$pmIndex[$candidate->pm_id]][$amIndex[$candidate->am_id]] = $candidate->score;
        }

        $pairs = $this->solve($scores, count($pmIds), count($amIds));

        $assignedIds = [];

        foreach ($pairs as $row => $column) {
            $assignedIds[] = $pmIds[$row].'|'.$amIds[$column];
        }

        if ($assignedIds === []) {
            return 0;
        }

        DB::transaction(function () use ($run, $assignedIds) {
            Candidate::where('run_id', $run->run_id)->update(['assigned' => false]);

            foreach (array_chunk($assignedIds, 200) as $chunk) {
                Candidate::where('run_id', $run->run_id)
                    ->where(function ($query) use ($chunk) {
                        foreach ($chunk as $pair) {
                            [$pmId, $amId] = explode('|', $pair, 2);
                            $query->orWhere(fn ($q) => $q->where('pm_id', $pmId)->where('am_id', $amId));
                        }
                    })
                    ->update(['assigned' => true]);
            }
        });

        return count($assignedIds);
    }

    /**
     * Hungarian algorithm (Kuhn–Munkres), O(n²m), on a sparse benefit matrix.
     *
     * Cells with no candidate are worth nothing rather than being forbidden,
     * so the solver stays feasible even when a body has no plausible partner;
     * those zero-benefit assignments are discarded afterwards, which is how a
     * body with no credible candidate correctly ends up assigned to nobody.
     *
     * @param  array<int, array<int, float>>  $scores
     * @return array<int, int> row index => column index
     */
    protected function solve(array $scores, int $rows, int $columns): array
    {
        // The implementation below requires rows <= columns; transpose if not.
        if ($rows > $columns) {
            $transposed = [];

            foreach ($scores as $r => $cells) {
                foreach ($cells as $c => $value) {
                    $transposed[$c][$r] = $value;
                }
            }

            return array_flip($this->solve($transposed, $columns, $rows));
        }

        $infinity = INF;

        // Costs are negated benefits: the algorithm minimises.
        $cost = function (int $i, int $j) use ($scores): float {
            return -($scores[$i - 1][$j - 1] ?? 0.0);
        };

        $u = array_fill(0, $rows + 1, 0.0);
        $v = array_fill(0, $columns + 1, 0.0);
        $p = array_fill(0, $columns + 1, 0);
        $way = array_fill(0, $columns + 1, 0);

        for ($i = 1; $i <= $rows; $i++) {
            $p[0] = $i;
            $j0 = 0;
            $minv = array_fill(0, $columns + 1, $infinity);
            $used = array_fill(0, $columns + 1, false);

            do {
                $used[$j0] = true;
                $i0 = $p[$j0];
                $delta = $infinity;
                $j1 = 0;

                for ($j = 1; $j <= $columns; $j++) {
                    if ($used[$j]) {
                        continue;
                    }

                    $current = $cost($i0, $j) - $u[$i0] - $v[$j];

                    if ($current < $minv[$j]) {
                        $minv[$j] = $current;
                        $way[$j] = $j0;
                    }

                    if ($minv[$j] < $delta) {
                        $delta = $minv[$j];
                        $j1 = $j;
                    }
                }

                for ($j = 0; $j <= $columns; $j++) {
                    if ($used[$j]) {
                        $u[$p[$j]] += $delta;
                        $v[$j] -= $delta;
                    } else {
                        $minv[$j] -= $delta;
                    }
                }

                $j0 = $j1;
            } while ($p[$j0] !== 0);

            // Walk the augmenting path back, flipping assignments.
            do {
                $j1 = $way[$j0];
                $p[$j0] = $p[$j1];
                $j0 = $j1;
            } while ($j0 !== 0);
        }

        $result = [];

        for ($j = 1; $j <= $columns; $j++) {
            $i = $p[$j];

            if ($i === 0) {
                continue;
            }

            // Discard filler assignments: a pairing that was never a candidate
            // carries no evidence and must not be presented as a conclusion.
            if (($scores[$i - 1][$j - 1] ?? 0.0) <= 0.0) {
                continue;
            }

            $result[$i - 1] = $j - 1;
        }

        return $result;
    }
}
