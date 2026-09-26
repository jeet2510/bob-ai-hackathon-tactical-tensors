<?php

namespace App\Services\Evaluation;

use App\Models\Observation;
use App\Services\Extraction\ExtractorInterface;

/**
 * An extractor that simply returns the gold normalisation.
 *
 * EVALUATION ONLY, AND NEVER A DEFAULT. This exists for exactly one purpose:
 * the dataset's published naive baseline was measured on the gold items, so
 * comparing our scorer against it on anything else would be comparing two
 * different things. Running the scorer on gold isolates *scoring* quality from
 * *extraction* quality and makes the baseline comparison honest.
 *
 * Its output is stored under a version string that says what it is, so no
 * table anywhere can quietly present an oracle run as a system result. The
 * end-to-end figure — the one that describes what the software would actually
 * do on a new incident — is always the rules-v1 or granite-v1 run.
 */
class GoldOracleExtractor implements ExtractorInterface
{
    public const VERSION = 'oracle-gold';

    /** @var array<string, list<array<string, mixed>>> */
    protected array $items;

    public function __construct(?GroundTruth $truth = null)
    {
        $this->items = ($truth ?? GroundTruth::at())->observationItems();
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @return list<array{item: array<string, mixed>, confidence: float}>
     */
    public function extract(Observation $observation): array
    {
        return array_map(
            // `truth_note` annotates why a case is hard; it is not part of the
            // item schema and must not reach the scorer.
            fn (array $item) => [
                'item' => array_diff_key($item, ['truth_note' => null]),
                'confidence' => 1.0,
            ],
            $this->items[$observation->obs_id] ?? [],
        );
    }
}
