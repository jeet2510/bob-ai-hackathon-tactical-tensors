<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Services\Extraction\RuleExtractor;
use App\Services\Matching\MatchingService;
use Illuminate\Console\Command;

class DviMatch extends Command
{
    protected $signature = 'dvi:match
        {--incident= : Incident id (defaults to the only one present)}
        {--extractor=rules-v1 : Which extractor\'s items to score}
        {--no-assignment : Skip the global one-to-one assignment}';

    protected $description = 'Cross-reference every body against every missing-person report';

    public function handle(MatchingService $matching): int
    {
        $incident = $this->option('incident')
            ? Incident::find($this->option('incident'))
            : Incident::query()->first();

        if (! $incident) {
            $this->error('No incident found. Run dvi:ingest first.');

            return self::FAILURE;
        }

        $this->info("Cross-referencing {$incident->name} ({$incident->incident_id})");

        $started = microtime(true);

        $stats = $matching->run(
            $incident,
            (string) ($this->option('extractor') ?: RuleExtractor::VERSION),
            ! $this->option('no-assignment'),
        );

        $elapsed = round(microtime(true) - $started, 1);

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Run', $stats['run_id']],
            ['Bodies', $stats['bodies']],
            ['Missing-person files', $stats['profiles']],
            ['Comparisons', number_format($stats['comparisons'])],
            ['Candidates retained', number_format($stats['candidates'])],
            ['Bodies with no credible candidate', $stats['refused']],
            ['Assigned one-to-one', $stats['assigned']],
            ['Elapsed', "{$elapsed}s"],
        ]);

        $this->line('  Candidates are ranked suggestions for which file to test first — not identifications.');

        return self::SUCCESS;
    }
}
