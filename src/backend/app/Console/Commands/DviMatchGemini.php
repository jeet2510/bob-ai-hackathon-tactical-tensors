<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\PmCase;
use App\Services\Matching\GeminiMatcher;
use Illuminate\Console\Command;

/**
 * Runs the Gemini multimodal shortlist-refinement for one body, or every
 * body in an incident that already has a deterministic candidate. Thin CLI
 * wrapper around GeminiMatcher — useful to exercise the feature against the
 * live API without a browser session, the same way dvi:match exercises the
 * deterministic scorer.
 */
class DviMatchGemini extends Command
{
    protected $signature = 'dvi:match-gemini
        {--incident= : Incident id (defaults to the only one present)}
        {--pm= : One pm_id to refine (defaults to every body with a rules-based candidate)}';

    protected $description = "Refine a body's rules-based shortlist with Gemini's multimodal reasoning";

    public function handle(GeminiMatcher $matcher): int
    {
        $incident = $this->option('incident')
            ? Incident::find($this->option('incident'))
            : Incident::query()->first();

        if (! $incident) {
            $this->error('No incident found. Run dvi:ingest first.');

            return self::FAILURE;
        }

        $run = $incident->latestRun();

        if (! $run) {
            $this->error('No deterministic match run exists for this incident yet. Run dvi:match first.');

            return self::FAILURE;
        }

        $bodies = $this->option('pm')
            ? PmCase::where('incident_id', $incident->incident_id)->where('pm_id', $this->option('pm'))->get()
            : PmCase::where('incident_id', $incident->incident_id)
                ->whereHas('candidates', fn ($q) => $q->where('run_id', $run->run_id))
                ->orderBy('pm_id')
                ->get();

        if ($bodies->isEmpty()) {
            $this->error('No matching body found with a rules-based candidate to refine.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($bodies as $body) {
            $result = $matcher->match($incident, $body);

            $rows[] = [
                $body->pm_id,
                $result['ai_available'] ? 'yes' : 'no',
                $result['run_id'] ?? '—',
                $result['candidates']->count(),
                $result['reason'] ?? '',
            ];
        }

        $this->table(['Body', 'AI available', 'Run', 'Candidates', 'Note'], $rows);
        $this->line('  Gemini candidates are advisory re-ranking of the existing shortlist — not identifications.');

        return self::SUCCESS;
    }
}
