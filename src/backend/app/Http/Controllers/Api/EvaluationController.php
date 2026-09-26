<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\MatchRun;
use App\Models\Observation;
use App\Models\ObservationNorm;
use App\Services\Evaluation\GroundTruth;
use App\Services\Evaluation\ItemComparator;
use App\Services\Evaluation\MatchEvaluator;

/**
 * The evaluation lab.
 *
 * This is the one controller permitted to read ground truth, and it exists
 * because a DVI tool that cannot say how often it is wrong has no business
 * being used. It reports three different things and keeps them apart:
 *
 *   - how well Stage 1 reads the forms, against the gold normalisation;
 *   - how well the scorer ranks, measured on gold items so that the
 *     comparison against the dataset's published baseline is like-for-like;
 *   - what the whole pipeline actually achieves end to end, which is lower,
 *     and is the number that describes the software.
 *
 * Nothing here feeds the pipeline. Truth enters only to be compared against.
 */
class EvaluationController extends Controller
{
    public function show(Incident $incident)
    {
        try {
            $truth = GroundTruth::at();
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ground truth is not available in this deployment; evaluation is disabled.',
            ], 404);
        }

        $runs = MatchRun::where('incident_id', $incident->incident_id)
            ->latest('created_at')
            ->get();

        $evaluator = new MatchEvaluator($truth);
        $results = [];

        // Most recent run per extractor, so the oracle and the real pipeline
        // are both represented without listing every historical run.
        foreach ($runs->unique('extractor_version') as $run) {
            foreach ([null, 'dev', 'holdout'] as $split) {
                $results[$run->extractor_version][$split ?? 'all'] = $evaluator->evaluate($run, $split);
            }
        }

        return response()->json([
            'success' => true,
            'baseline' => MatchEvaluator::BASELINE,
            'runs' => $results,
            'extraction' => $this->extractionQuality($truth),
            'notes' => [
                'Baseline figures are the dataset\'s own naive matcher, measured on the gold normalisation.',
                'The oracle-gold rows isolate the scorer by feeding it perfect Stage-1 items; they are not a system result.',
                'The end-to-end rows are what the pipeline achieves unaided, and are the honest figure.',
                'Holdout pairs were never used to choose weights.',
            ],
        ]);
    }

    /**
     * Stage-1 item precision and recall per extractor, per category and per
     * language — the ceiling on everything downstream.
     *
     * @return array<string, mixed>
     */
    protected function extractionQuality(GroundTruth $truth): array
    {
        $gold = $truth->observationItems();
        $observations = Observation::query()->get(['obs_id', 'lang', 'category']);

        $versions = ObservationNorm::query()->distinct()->pluck('extractor_version');
        $out = [];

        foreach ($versions as $version) {
            $predicted = ObservationNorm::where('extractor_version', $version)
                ->get(['obs_id', 'norm_json'])
                ->groupBy('obs_id');

            $totals = ['matched' => 0, 'predicted' => 0, 'gold' => 0];
            $byLang = [];
            $byCategory = [];

            foreach ($observations as $observation) {
                $goldItems = $gold[$observation->obs_id] ?? [];
                $predItems = ($predicted[$observation->obs_id] ?? collect())->pluck('norm_json')->all();

                $counts = ItemComparator::align($predItems, $goldItems);

                foreach ($counts as $k => $v) {
                    $totals[$k] += $v;
                    $byLang[$observation->lang][$k] = ($byLang[$observation->lang][$k] ?? 0) + $v;
                }

                foreach (array_unique(array_merge(
                    array_column($predItems, 'category'),
                    array_column($goldItems, 'category'),
                )) as $category) {
                    if ($category === null) {
                        continue;
                    }

                    $sub = ItemComparator::align(
                        array_values(array_filter($predItems, fn ($i) => ($i['category'] ?? null) === $category)),
                        array_values(array_filter($goldItems, fn ($i) => ($i['category'] ?? null) === $category)),
                    );

                    foreach ($sub as $k => $v) {
                        $byCategory[$category][$k] = ($byCategory[$category][$k] ?? 0) + $v;
                    }
                }
            }

            $out[$version] = [
                'overall' => $this->prf($totals),
                'by_lang' => array_map([$this, 'prf'], $byLang),
                'by_category' => array_map([$this, 'prf'], $byCategory),
            ];
        }

        return $out;
    }

    /**
     * @param  array{matched: int, predicted: int, gold: int}  $c
     * @return array<string, float|int>
     */
    protected function prf(array $c): array
    {
        $precision = ($c['predicted'] ?? 0) > 0 ? $c['matched'] / $c['predicted'] : 0.0;
        $recall = ($c['gold'] ?? 0) > 0 ? $c['matched'] / $c['gold'] : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        return [
            'gold' => $c['gold'] ?? 0,
            'predicted' => $c['predicted'] ?? 0,
            'matched' => $c['matched'] ?? 0,
            'precision' => round($precision * 100, 1),
            'recall' => round($recall * 100, 1),
            'f1' => round($f1 * 100, 1),
        ];
    }
}
