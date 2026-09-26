<?php

namespace App\Console\Commands;

use App\Models\Observation;
use App\Models\ObservationNorm;
use App\Services\Evaluation\GroundTruth;
use App\Services\Evaluation\ItemComparator;
use Illuminate\Console\Command;

/**
 * Measures Stage 1 against the gold normalisation.
 *
 * This number is the ceiling on everything downstream: the scorer can only
 * compare items the extractor managed to read, so a category with poor recall
 * here is invisible to matching no matter how good the weights are.
 */
class DviEvaluateExtraction extends Command
{
    protected $signature = 'dvi:evaluate:extraction
        {--extractor=rules-v1 : Extractor version to score}
        {--truth= : Path to the ground_truth directory}
        {--by=category : Break down by category|lang|record_type}';

    protected $description = 'Score Stage-1 extraction (precision/recall/F1) against the gold normalisation';

    public function handle(): int
    {
        $version = (string) $this->option('extractor');

        try {
            $gold = GroundTruth::at($this->option('truth'))->observationItems();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $observations = Observation::query()->get()->keyBy('obs_id');

        if ($observations->isEmpty()) {
            $this->error('No observations found. Run dvi:ingest first.');

            return self::FAILURE;
        }

        $predicted = ObservationNorm::query()
            ->where('extractor_version', $version)
            ->get()
            ->groupBy('obs_id');

        if ($predicted->isEmpty()) {
            $this->error("No items from '{$version}'. Run dvi:extract --extractor={$version} first.");

            return self::FAILURE;
        }

        $by = (string) $this->option('by');
        $totals = ['matched' => 0, 'predicted' => 0, 'gold' => 0];
        $groups = [];

        foreach ($observations as $obsId => $observation) {
            $goldItems = $gold[$obsId] ?? [];
            $predItems = ($predicted[$obsId] ?? collect())->pluck('norm_json')->all();

            // Group by the observation's attribute, except for category, where
            // the *item* category is the meaningful unit — one appearance box
            // produces hair, eyes and skin items that succeed independently.
            if ($by === 'category') {
                foreach ($this->byItemCategory($predItems, $goldItems) as $cat => $counts) {
                    $groups[$cat] ??= ['matched' => 0, 'predicted' => 0, 'gold' => 0];

                    foreach ($counts as $k => $v) {
                        $groups[$cat][$k] += $v;
                    }
                }
            } else {
                $key = $observation->{$by} ?? 'unknown';
                $groups[$key] ??= ['matched' => 0, 'predicted' => 0, 'gold' => 0];
                $counts = ItemComparator::align($predItems, $goldItems);

                foreach ($counts as $k => $v) {
                    $groups[$key][$k] += $v;
                }
            }

            $counts = ItemComparator::align($predItems, $goldItems);

            foreach ($counts as $k => $v) {
                $totals[$k] += $v;
            }
        }

        $this->newLine();
        $this->info("Stage-1 extraction quality — {$version}");
        $this->line('Item-level, one-to-one alignment against gold.');
        $this->newLine();

        $rows = [];

        foreach ($groups as $key => $counts) {
            $rows[] = $this->row((string) $key, $counts);
        }

        usort($rows, fn ($a, $b) => $b[3] <=> $a[3]);
        $rows[] = ['───────────', '───', '───', '───', '───', '───'];
        $rows[] = $this->row('ALL', $totals);

        $this->table(['Group', 'Gold', 'Pred', 'Hit', 'Precision', 'Recall / F1'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $predicted
     * @param  list<array<string, mixed>>  $gold
     * @return array<string, array{matched: int, predicted: int, gold: int}>
     */
    protected function byItemCategory(array $predicted, array $gold): array
    {
        $categories = array_unique(array_merge(
            array_column($predicted, 'category'),
            array_column($gold, 'category'),
        ));

        $out = [];

        foreach ($categories as $category) {
            if ($category === null) {
                continue;
            }

            $out[$category] = ItemComparator::align(
                array_values(array_filter($predicted, fn ($i) => ($i['category'] ?? null) === $category)),
                array_values(array_filter($gold, fn ($i) => ($i['category'] ?? null) === $category)),
            );
        }

        return $out;
    }

    /**
     * @param  array{matched: int, predicted: int, gold: int}  $c
     * @return array<int, string|int>
     */
    protected function row(string $label, array $c): array
    {
        $precision = $c['predicted'] > 0 ? $c['matched'] / $c['predicted'] : 0.0;
        $recall = $c['gold'] > 0 ? $c['matched'] / $c['gold'] : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        return [
            $label,
            $c['gold'],
            $c['predicted'],
            $c['matched'],
            sprintf('%5.1f%%', $precision * 100),
            sprintf('%5.1f%%  /  %5.1f%%', $recall * 100, $f1 * 100),
        ];
    }
}
