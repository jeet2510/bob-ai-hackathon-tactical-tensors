<?php

namespace App\Services\Evaluation;

use RuntimeException;

/**
 * Reader for the dataset's hidden ground truth.
 *
 * EVALUATION ONLY. Nothing under App\Http, App\Services\Extraction or
 * App\Services\Matching may reference this class: the moment truth reaches the
 * pipeline, every accuracy figure the system reports becomes a statement about
 * itself. `dvi:doctor` enforces that separation.
 */
class GroundTruth
{
    public function __construct(
        protected string $dir,
    ) {
        if (! is_dir($dir)) {
            throw new RuntimeException("Ground-truth directory not found: {$dir}");
        }
    }

    public static function at(?string $path = null): self
    {
        return new self(rtrim($path ?: config('dvi.ground_truth_path'), '/'));
    }

    /**
     * Gold Stage-1 output, keyed by observation id: the items a perfect
     * extractor would have produced.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function observationItems(): array
    {
        $out = [];

        foreach ($this->jsonl('gold_observation_norm.jsonl') as $row) {
            $out[$row['obs_id']] = $row['items'] ?? [];
        }

        return $out;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function photoItems(): array
    {
        $out = [];

        foreach ($this->jsonl('gold_photo_norm.jsonl') as $row) {
            $out[$row['photo_id']] = $row['items'] ?? [];
        }

        return $out;
    }

    /**
     * The PM↔AM truth table.
     *
     * Rows with an empty `pm_id` are missing people whose body was never
     * recovered; rows with an empty `am_id` are bodies nobody reported. Both
     * are as important to score as the true pairs — refusing to pair them is
     * the behaviour under test.
     *
     * @return list<array<string, string>>
     */
    public function links(): array
    {
        $path = "{$this->dir}/truth_links.csv";

        if (! is_readable($path)) {
            throw new RuntimeException("truth_links.csv not readable at {$path}");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $rows[] = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
        }

        fclose($handle);

        return $rows;
    }

    /**
     * True pairs only: pm_id => am_id.
     *
     * @param  string|null  $split  'dev' | 'holdout' | null for both
     * @return array<string, string>
     */
    public function truePairs(?string $split = null): array
    {
        $pairs = [];

        foreach ($this->links() as $row) {
            if ($row['pm_id'] === '' || $row['am_id'] === '') {
                continue;
            }

            if ($split !== null && $row['split'] !== $split) {
                continue;
            }

            $pairs[$row['pm_id']] = $row['am_id'];
        }

        return $pairs;
    }

    /**
     * Bodies that genuinely have no ante-mortem partner. The correct output
     * for each is "no credible candidate".
     *
     * @return list<string>
     */
    public function bodiesWithNoMatch(?string $split = null): array
    {
        $out = [];

        foreach ($this->links() as $row) {
            if ($row['pm_id'] !== '' && $row['am_id'] === '') {
                if ($split === null || $row['split'] === $split) {
                    $out[] = $row['pm_id'];
                }
            }
        }

        return $out;
    }

    /**
     * Case type and difficulty tags per PM id, for slicing results.
     *
     * @return array<string, array{case_type: string, tags: list<string>, split: string}>
     */
    public function caseMeta(): array
    {
        $out = [];

        foreach ($this->links() as $row) {
            if ($row['pm_id'] === '') {
                continue;
            }

            $out[$row['pm_id']] = [
                'case_type' => $row['case_type'],
                'tags' => array_values(array_filter(explode(';', $row['difficulty_tags'] ?? ''))),
                'split' => $row['split'],
            ];
        }

        return $out;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    protected function jsonl(string $file): \Generator
    {
        $path = "{$this->dir}/{$file}";

        if (! is_readable($path)) {
            throw new RuntimeException("Ground-truth file not readable: {$path}");
        }

        $handle = fopen($path, 'r');

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
