<?php

namespace App\Console\Commands;

use App\Models\MatchRun;
use App\Services\Evaluation\GroundTruth;
use App\Services\Evaluation\MatchEvaluator;
use Illuminate\Console\Command;

class DviEvaluate extends Command
{
    protected $signature = 'dvi:evaluate
        {--run= : Match run to score (defaults to the latest)}
        {--split= : dev | holdout (defaults to all)}
        {--truth= : Path to the ground_truth directory}
        {--misses : List the pairings that were not ranked first}
        {--json : Emit the raw metrics as JSON}';

    protected $description = 'Score a match run against the hidden ground truth';

    public function handle(): int
    {
        $run = $this->option('run')
            ? MatchRun::find($this->option('run'))
            : MatchRun::latest('created_at')->first();

        if (! $run) {
            $this->error('No match run found. Run dvi:match first.');

            return self::FAILURE;
        }

        try {
            $truth = GroundTruth::at($this->option('truth'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $split = $this->option('split') ?: null;
        $result = (new MatchEvaluator($truth))->evaluate($run, $split);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('DVI match evaluation');
        $this->line("  run        {$result['run_id']}");
        $this->line("  extractor  {$result['extractor_version']}");
        $this->line("  scorer     {$result['scorer_version']}");
        $this->line("  split      {$result['split']}");
        $this->newLine();

        $isFullRun = $split === null;
        $baseline = MatchEvaluator::BASELINE;
        $n = $result['true_pairs'];

        $rows = [
            $this->compare('Recall@1', $result['recall_at_1'], $n, $isFullRun ? $baseline['recall_at_1'] : null, $baseline['true_pairs']),
            $this->compare('Recall@3', $result['recall_at_3'], $n, $isFullRun ? $baseline['recall_at_3'] : null, $baseline['true_pairs']),
            $this->compare('One-to-one assignment', $result['assignment_correct'], $n, $isFullRun ? $baseline['assignment'] : null, $baseline['true_pairs']),
            $this->compare(
                'Correct refusals',
                $result['refusals_correct'],
                $result['refusal_total'],
                $isFullRun ? $baseline['refusals_correct'] : null,
                $baseline['refusal_total'],
            ),
            $this->compare('False exclusions (lower is better)', $result['false_exclusions'], $n, $isFullRun ? $baseline['false_exclusions'] : null, $baseline['true_pairs']),
        ];

        $this->table(['Metric', 'This run', 'Naive baseline', 'Delta'], $rows);

        $this->line('  Baseline figures are the dataset\'s published naive matcher, measured on the gold');
        $this->line('  normalisation. A like-for-like comparison needs --extractor=oracle-gold on dvi:match.');

        if ($result['per_case_type'] !== []) {
            $this->newLine();
            $this->info('By case type');
            $this->table(['Case type', 'Pairs', 'Top-1', 'Top-3'], $this->breakdown($result['per_case_type']));
        }

        if ($result['per_tag'] !== []) {
            $this->newLine();
            $this->info('By injected difficulty');
            $this->table(['Tag', 'Pairs', 'Top-1', 'Top-3'], $this->breakdown($result['per_tag']));
        }

        if ($this->option('misses') && $result['misses'] !== []) {
            $this->newLine();
            $this->info('Pairings not ranked first');
            $this->table(
                ['Body', 'Expected', 'Ranked first', 'True rank', 'Case', 'Tags'],
                array_map(fn ($m) => [
                    $m['pm_id'], $m['expected'], $m['got'] ?? '—',
                    $m['true_rank'] ?? 'not offered', $m['case_type'], $m['tags'],
                ], $result['misses']),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function compare(string $label, int $value, int $total, ?int $baseline, int $baselineTotal): array
    {
        $delta = '—';

        if ($baseline !== null) {
            $difference = $value - $baseline;
            $delta = $difference === 0 ? 'same' : sprintf('%+d', $difference);
        }

        return [
            $label,
            sprintf('%d / %d', $value, $total),
            $baseline === null ? '—' : sprintf('%d / %d', $baseline, $baselineTotal),
            $delta,
        ];
    }

    /**
     * @param  array<string, array{n: int, top1: int, top3: int}>  $bucket
     * @return list<array<int, string>>
     */
    protected function breakdown(array $bucket): array
    {
        uasort($bucket, fn ($a, $b) => $b['n'] <=> $a['n']);

        return array_map(
            fn ($key, $c) => [
                $key,
                (string) $c['n'],
                sprintf('%d  (%3.0f%%)', $c['top1'], $c['n'] > 0 ? $c['top1'] / $c['n'] * 100 : 0),
                sprintf('%d  (%3.0f%%)', $c['top3'], $c['n'] > 0 ? $c['top3'] / $c['n'] * 100 : 0),
            ],
            array_keys($bucket),
            $bucket,
        );
    }
}
