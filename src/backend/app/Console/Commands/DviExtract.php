<?php

namespace App\Console\Commands;

use App\Models\Observation;
use App\Models\ObservationNorm;
use App\Services\Evaluation\GoldOracleExtractor;
use App\Services\Extraction\ExtractorInterface;
use App\Services\Extraction\GraniteExtractor;
use App\Services\Extraction\RuleExtractor;
use App\Services\Extraction\WatsonxClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stage 1 — turn every free-text form box into normalised items.
 */
class DviExtract extends Command
{
    protected $signature = 'dvi:extract
        {--extractor=rules-v1 : rules-v1 | granite-v1 | oracle-gold (evaluation only)}
        {--fresh : Discard existing items from this extractor first}
        {--keep-reviewed : Preserve items a human has corrected}';

    protected $description = 'Normalise observation free text into structured INTERPOL items';

    public function handle(): int
    {
        $version = (string) $this->option('extractor');

        try {
            $extractor = $this->resolve($version);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // Missing credentials and an unknown extractor name are both
            // operator errors, not crashes — say what to do, not a stack trace.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $query = ObservationNorm::where('extractor_version', $version);

            // A reviewer's correction outranks any extractor and is not
            // discarded by a re-run unless explicitly asked for.
            if ($this->option('keep-reviewed')) {
                $query->whereNull('reviewed_by');
            }

            $this->warn("Deleted {$query->delete()} existing {$version} items.");
        }

        $observations = Observation::query()->orderBy('obs_id')->get();
        $bar = $this->output->createProgressBar($observations->count());
        $bar->start();

        $itemCount = 0;
        $emptyBoxes = [];

        DB::transaction(function () use ($observations, $extractor, $version, $bar, &$itemCount, &$emptyBoxes) {
            $batch = [];
            $now = now();

            foreach ($observations as $observation) {
                $results = $extractor->extract($observation);

                if ($results === []) {
                    $emptyBoxes[] = $observation->category;
                }

                foreach ($results as $index => $result) {
                    $batch[] = [
                        'obs_id' => $observation->obs_id,
                        'item_index' => $index,
                        'norm_json' => json_encode($result['item'], JSON_UNESCAPED_UNICODE),
                        'extractor_version' => $version,
                        'confidence' => $result['confidence'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $itemCount++;
                }

                if (count($batch) >= 500) {
                    ObservationNorm::upsert($batch, ['obs_id', 'item_index', 'extractor_version']);
                    $batch = [];
                }

                $bar->advance();
            }

            if ($batch !== []) {
                ObservationNorm::upsert($batch, ['obs_id', 'item_index', 'extractor_version']);
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            '%s produced %s items from %s form boxes.',
            $version,
            number_format($itemCount),
            number_format($observations->count()),
        ));

        if ($emptyBoxes !== []) {
            // Boxes yielding nothing are where the extractor is blind; naming
            // the categories points straight at the lexicon gap.
            $breakdown = collect($emptyBoxes)->countBy()->sortDesc()
                ->map(fn ($n, $c) => "{$c}={$n}")->implode(', ');

            $this->warn(count($emptyBoxes)." boxes yielded no items ({$breakdown}).");
        }

        return self::SUCCESS;
    }

    protected function resolve(string $version): ExtractorInterface
    {
        if ($version === GoldOracleExtractor::VERSION) {
            // Loud on purpose. Items from this run are ground truth, and any
            // figure derived from them describes the scorer alone.
            $this->warn('Using the gold oracle. This reads ground truth and is for evaluation only —');
            $this->warn('never present an oracle-gold run as an end-to-end system result.');

            return new GoldOracleExtractor;
        }

        if ($version === GraniteExtractor::VERSION) {
            $extractor = new GraniteExtractor(new WatsonxClient);
            $extractor->assertUsable();

            $this->line('  Using IBM Granite on watsonx.ai. Completions are cached for replay.');

            return $extractor;
        }

        return match ($version) {
            RuleExtractor::VERSION => new RuleExtractor,
            default => throw new \InvalidArgumentException(
                "Unknown extractor '{$version}'. Available: ".RuleExtractor::VERSION
                .', '.GraniteExtractor::VERSION
                .', '.GoldOracleExtractor::VERSION.' (evaluation only)',
            ),
        };
    }
}
