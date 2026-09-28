<?php

namespace App\Services\Assistant;

use App\Models\Incident;
use App\Services\Extraction\GeminiClient;
use App\Services\Reporting\ReconciliationReport;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "DVI Assistant" — a read-only briefing and Q&A layer over one incident's
 * current data. It never queries the database on its own initiative and
 * never executes anything a user writes; every call is handed a bounded,
 * pre-computed JSON snapshot (see snapshot()) built from the same
 * ReconciliationReport data already shown to reviewers, and can only talk
 * about what is in that snapshot. It cannot record a decision, trigger the
 * matcher, or change any record — advisory only, exactly like every other
 * AI surface in this app.
 */
class DviAssistant
{
    protected const PERSONA = <<<TEXT
        You are "DVI Assistant", embedded in a Disaster Victim Identification
        coordination tool. You answer a reviewer's questions about ONE
        specific incident, using only the JSON data snapshot given to you —
        never invent names, counts, or case details that are not in it.

        Rules:
        - This tool never asserts an identification. INTERPOL procedure
          requires a match on a primary identifier — fingerprints, dental
          records or DNA. If asked whether something "is a match", explain
          that only a reviewer can decide that, after the right test.
        - If the snapshot does not contain what you are asked about, say so
          plainly rather than guessing or estimating.
        - Be concise and operational. A coordinator wants the answer, not an
          essay — a few sentences, plain prose, no markdown formatting.
        - You cannot take any action. You cannot record a decision, run the
          matcher, or edit a record — you can only describe what is already
          in the data and suggest what the reviewer might look at next.
        - Never speculate about who a specific unidentified body "really is"
          beyond what its ranked candidates and their evidence already say.
        TEXT;

    public function __construct(
        protected GeminiClient $client,
        protected ReconciliationReport $report,
    ) {}

    /**
     * A short, on-demand narrative briefing for the incident dashboard.
     *
     * @return array{ai_available: bool, text: ?string, reason: ?string}
     */
    public function insight(Incident $incident): array
    {
        $snapshot = $this->snapshot($incident);

        $instruction = self::PERSONA."\n\nWrite a short (3-5 sentence) plain-language briefing for a ".
            "coordinator who has just opened this incident's dashboard: the headline numbers, anything ".
            "urgent (no credible candidate, no confirmation route), and one clear next action. Base it ".
            "only on the JSON snapshot below.\n\n".json_encode($snapshot);

        return $this->converse($instruction, [], 'insight');
    }

    /**
     * One turn of the chat sidebar. `history` is the visible prior turns —
     * the client holds the conversation, nothing is persisted server-side.
     *
     * @param  list<array{role: 'user'|'assistant', text: string}>  $history
     * @return array{ai_available: bool, text: ?string, reason: ?string}
     */
    public function reply(Incident $incident, string $message, array $history): array
    {
        $snapshot = $this->snapshot($incident);

        $instruction = self::PERSONA."\n\nCurrent data snapshot for this incident (JSON, authoritative — ".
            "the only source of truth you have):\n".json_encode($snapshot);

        $turns = Collection::make($history)
            ->map(fn (array $turn) => [
                'role' => ($turn['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'text' => (string) ($turn['text'] ?? ''),
            ])
            ->filter(fn (array $turn) => $turn['text'] !== '')
            ->values()
            ->all();

        $turns[] = ['role' => 'user', 'text' => $message];

        return $this->converse($instruction, $turns, 'chat');
    }

    /**
     * @param  list<array{role: 'user'|'model', text: string}>  $turns
     * @return array{ai_available: bool, text: ?string, reason: ?string}
     */
    protected function converse(string $instruction, array $turns, string $kind): array
    {
        $entryTurns = $turns === [] ? [['role' => 'user', 'text' => 'Give me the briefing.']] : $turns;

        try {
            $result = $this->client->converse(
                (string) config('gemini.assistant_model'),
                $instruction,
                $entryTurns,
                config('gemini.assistant_prompt_version')."-{$kind}",
            );
        } catch (Throwable $e) {
            return ['ai_available' => false, 'text' => null, 'reason' => $e->getMessage()];
        }

        if ($result['text'] === '') {
            return ['ai_available' => false, 'text' => null, 'reason' => 'Bob by IBM returned an empty response.'];
        }

        return ['ai_available' => true, 'text' => $result['text'], 'reason' => null];
    }

    /**
     * A compact, bounded snapshot of the incident's current state — the only
     * thing the model is allowed to reason from. Built from
     * ReconciliationReport::build(), the same vetted, ground-truth-safe data
     * already shown to reviewers, trimmed so a large incident never blows up
     * the prompt: full detail for what needs attention, counts for the rest.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(Incident $incident): array
    {
        $full = $this->report->build($incident);

        $needsAttention = Collection::make($full['sections'])
            ->filter(fn (array $s) => $s['outcome'] !== 'referred_for_confirmation');

        return [
            'incident' => $full['incident'],
            'run' => $full['run'],
            'summary' => $full['summary'],
            'bodies_needing_attention' => $needsAttention->take(25)->map(fn (array $s) => [
                'pm_id' => $s['body']['pm_id'],
                'body_condition' => $s['body']['body_condition'],
                'found_place' => $s['body']['found_place'],
                'outcome' => $s['outcome'],
                'top_candidate' => $s['candidates'][0] ?? null,
            ])->values(),
            'bodies_needing_attention_total' => $needsAttention->count(),
            'families_still_waiting' => Collection::make($full['outstanding_profiles'])->take(25)->values(),
            'families_still_waiting_total' => count($full['outstanding_profiles']),
        ];
    }
}
