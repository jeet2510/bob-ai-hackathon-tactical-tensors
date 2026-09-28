<?php

namespace App\Services\Matching;

use App\Models\AmFile;
use App\Models\Candidate;
use App\Models\Incident;
use App\Models\MatchEvidence;
use App\Models\MatchRun;
use App\Models\PhotoEvidence;
use App\Models\PmCase;
use App\Services\Extraction\GeminiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refines one body's deterministic shortlist (matcher-v1/evidence-v1) using
 * Gemini's multimodal reasoning over the body's and each candidate's
 * photographs alongside the rules engine's own evidence rationale — the
 * vision step docs/solution-overview.md documents as "specified, not built".
 *
 * Deliberately does not search the whole incident: it only ever re-ranks a
 * body's existing, already-evidenced shortlist, so it can never surface a
 * pairing the deterministic scorer found no individuating evidence for at
 * all, and never touches match_run rows the rest of the app depends on
 * (see Incident::latestRun()).
 */
class GeminiMatcher
{
    public const VERSION = 'gemini-v1';

    public function __construct(
        protected GeminiClient $client,
        protected PhotoFileResolver $photos,
    ) {}

    /**
     * @return array{run_id: ?string, candidates: Collection<int, Candidate>, ai_available: bool, reason: ?string, summary: ?string}
     */
    public function match(Incident $incident, PmCase $body): array
    {
        $sourceRun = $incident->latestRun();

        if (! $sourceRun) {
            return $this->unavailable('No deterministic match run exists for this incident yet. Run the matcher first.');
        }

        $shortlist = Candidate::where('run_id', $sourceRun->run_id)
            ->where('pm_id', $body->pm_id)
            ->orderBy('rank')
            ->limit((int) config('gemini.match_shortlist_size'))
            ->get();

        if ($shortlist->isEmpty()) {
            return $this->unavailable('No rules-based candidates to refine — the deterministic pass found nothing worth testing for this body.');
        }

        $profiles = AmFile::whereIn('am_id', $shortlist->pluck('am_id'))->get()->keyBy('am_id');

        $evidenceByAm = MatchEvidence::where('run_id', $sourceRun->run_id)
            ->where('pm_id', $body->pm_id)
            ->whereIn('am_id', $shortlist->pluck('am_id'))
            ->orderByRaw('abs(llr) desc')
            ->get()
            ->groupBy('am_id');

        $parts = $this->buildParts($body, $shortlist, $profiles, $evidenceByAm);

        try {
            $result = $this->client->generateStructuredMultipart(
                (string) config('gemini.match_model'),
                $parts,
                GeminiMatchSchema::schema(),
                (string) config('gemini.match_prompt_version'),
            );
        } catch (Throwable $e) {
            return $this->unavailable($e->getMessage());
        }

        $validAmIds = $shortlist->pluck('am_id')->all();

        $picked = collect($result['json']['candidates'] ?? [])
            ->filter(fn ($c) => is_array($c) && in_array($c['am_id'] ?? null, $validAmIds, true))
            ->unique('am_id')
            ->take(3)
            ->values();

        $summary = trim((string) ($result['json']['summary'] ?? '')) ?: null;

        if ($picked->isEmpty()) {
            return ['run_id' => null, 'candidates' => collect(), 'ai_available' => true, 'summary' => $summary,
                'reason' => 'Bob by IBM found no candidate in the shortlist with credible visual or evidentiary support.'];
        }

        return $this->persist($incident, $body, $sourceRun, $shortlist->keyBy('am_id'), $picked, $summary);
    }

    /**
     * @return array{run_id: null, candidates: Collection<int, Candidate>, ai_available: bool, reason: string, summary: null}
     */
    protected function unavailable(string $reason): array
    {
        return ['run_id' => null, 'candidates' => collect(), 'ai_available' => false, 'reason' => $reason, 'summary' => null];
    }

    /**
     * Builds the ordered text/image parts sent to Gemini: instructions, the
     * body's summary and photos, then each shortlisted candidate's evidence
     * summary and photos. Deliberately never includes reported_name,
     * examiner_name, reporter contact fields, or any other name/PII field —
     * only the structural attributes and rules-evidence rationale the
     * deterministic scorer itself already treats as evidence.
     *
     * @param  Collection<int, Candidate>  $shortlist
     * @param  Collection<string, AmFile>  $profiles
     * @param  Collection<string, Collection<int, MatchEvidence>>  $evidenceByAm
     * @return list<array{type: 'text', text: string}|array{type: 'image', mimeType: string, data: string}>
     */
    protected function buildParts(PmCase $body, Collection $shortlist, Collection $profiles, Collection $evidenceByAm): array
    {
        $parts = [
            ['type' => 'text', 'text' => GeminiMatchSchema::instructions()],
            ['type' => 'text', 'text' => $this->bodySummary($body)],
        ];

        array_push($parts, ...$this->photoParts($body->pm_id, 'PM', 'Post-mortem photograph'));

        foreach ($shortlist as $candidate) {
            $profile = $profiles->get($candidate->am_id);

            if (! $profile) {
                continue;
            }

            $parts[] = ['type' => 'text', 'text' => $this->candidateSummary($candidate, $profile, $evidenceByAm->get($candidate->am_id, collect()))];
            array_push($parts, ...$this->photoParts($profile->am_id, 'AM', "Family-submitted photograph for candidate {$profile->am_id}"));
        }

        $parts[] = ['type' => 'text', 'text' =>
            'Valid am_id values you may choose from: '.$shortlist->pluck('am_id')->implode(', ').
            "\n\nReturn your ranked assessment now, per the schema."];

        return $parts;
    }

    protected function bodySummary(PmCase $body): string
    {
        return "RECOVERED BODY (the subject of this examination):\n".
            "Sex: {$body->sex}; age estimate: {$body->ageRange()}; height: ".($body->height_cm ?? 'unknown')." cm.\n".
            "Condition on recovery: {$body->body_condition}".($body->isDegraded() ? ' (degraded — description may be limited)' : '').".\n".
            'Identifier status — DNA: '.($body->dna_status ?? 'unknown').', dental: '.($body->dental_status ?? 'unknown').
            ', prints: '.($body->print_status ?? 'unknown').".\n";
    }

    protected function candidateSummary(Candidate $candidate, AmFile $profile, Collection $evidence): string
    {
        $lines = ["CANDIDATE am_id={$profile->am_id} (rules-engine rank {$candidate->rank}, band {$candidate->confidence_band}):"];
        $lines[] = 'Sex: '.($profile->sex ?? 'unknown').'; age: '.($profile->age ?? 'unknown').
            '; height: '.($profile->height_cm_reported ?? $profile->height_text ?? 'unknown');
        $lines[] = 'Family dental records available: '.($profile->dental_records_available ? 'yes' : 'no').
            '; prints on file: '.($profile->prints_on_file ? 'yes' : 'no');
        $lines[] = 'Rules-engine text evidence for this pairing:';

        foreach ($evidence as $row) {
            if (! $row->isInformative()) {
                continue;
            }

            $lines[] = "- {$row->category} ({$row->verdict}): ".($row->rationale ?: 'no detail recorded');
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<array{type: 'image', mimeType: string, data: string}>
     */
    protected function photoParts(string $recordId, string $recordType, string $label): array
    {
        $cap = (int) config('gemini.match_photos_per_record');

        $photos = PhotoEvidence::where('record_type', $recordType)
            ->where('record_id', $recordId)
            ->matchable()
            ->orderBy('photo_id')
            ->limit($cap)
            ->get();

        $parts = [];

        foreach ($photos as $photo) {
            $resolved = $this->photos->resolve($photo);

            if (! $resolved) {
                continue;
            }

            $parts[] = ['type' => 'text', 'text' => "{$label} (modality: {$photo->modality}):"];
            $parts[] = ['type' => 'image', 'mimeType' => $resolved['mime'], 'data' => base64_encode(file_get_contents($resolved['path']))];
        }

        return $parts;
    }

    /**
     * @param  Collection<string, Candidate>  $shortlistByAm
     * @param  Collection<int, array<string, mixed>>  $picked
     * @return array{run_id: string, candidates: Collection<int, Candidate>, ai_available: bool, reason: null, summary: ?string}
     */
    protected function persist(Incident $incident, PmCase $body, MatchRun $sourceRun, Collection $shortlistByAm, Collection $picked, ?string $summary): array
    {
        return DB::transaction(function () use ($incident, $body, $sourceRun, $shortlistByAm, $picked, $summary) {
            $run = MatchRun::create([
                'run_id' => (string) Str::uuid(),
                'incident_id' => $incident->incident_id,
                'scorer_version' => self::VERSION,
                'extractor_version' => $sourceRun->extractor_version,
                'config_hash' => substr(hash('sha256', config('gemini.match_prompt_version').config('gemini.match_model')), 0, 16),
                'config' => [
                    'source_run_id' => $sourceRun->run_id,
                    'model' => config('gemini.match_model'),
                    'prompt_version' => config('gemini.match_prompt_version'),
                ],
                'assignment_applied' => false,
            ]);

            $rows = $picked->values()->map(function (array $c, int $i) use ($shortlistByAm, $run, $body) {
                $source = $shortlistByAm->get($c['am_id']);

                return [
                    'run_id' => $run->run_id,
                    'pm_id' => $body->pm_id,
                    'am_id' => $c['am_id'],
                    'score' => $source->score,
                    'rank' => $i + 1,
                    'confidence_band' => $c['confidence'],
                    'coverage' => $source->coverage,
                    'assigned' => false,
                    'recommended_route' => $source->getRawOriginal('recommended_route'),
                    'ai_rationale' => $c['rationale'] ?? null,
                    'ai_visual_notes' => $c['visual_notes'] ?? null,
                ];
            })->all();

            Candidate::insert($rows);

            $run->stats = ['candidates' => count($rows), 'source_run_id' => $sourceRun->run_id, 'summary' => $summary];
            $run->save();

            return [
                'run_id' => $run->run_id,
                'candidates' => Candidate::where('run_id', $run->run_id)->orderBy('rank')->get(),
                'ai_available' => true,
                'reason' => null,
                'summary' => $summary,
            ];
        });
    }
}
