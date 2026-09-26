<?php

namespace App\Services\Matching;

use App\Models\AmFile;
use App\Models\Candidate;
use App\Models\Incident;
use App\Models\MatchEvidence;
use App\Models\MatchRun;
use App\Models\ObservationNorm;
use App\Models\PmCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cross-references every body in an incident against every missing-person
 * report, and records a ranked, explained candidate list for each.
 *
 * This is the work that took teams seventy-two hours by hand at Balasore.
 */
class MatchingService
{
    public const VERSION = 'matcher-v1';

    /**
     * Candidates retained per body. The dashboard shows three; the rest are
     * kept so a reviewer who rules all three out has somewhere to go without
     * re-running the incident.
     */
    public const RETAINED_PER_BODY = 10;

    /**
     * Total evidence below which a pairing is not retained at all.
     *
     * Deliberately *not* the same threshold that decides whether a candidate
     * is credible. Retention is about keeping a reviewer's options open —
     * a weak pairing still belongs in the list they can page through — while
     * the confidence band decides what the system is willing to assert. Tying
     * the two together meant that making the system more cautious also made it
     * blind, dropping true partners out of the candidate list entirely.
     */
    public const SCORE_FLOOR = 0.5;

    public function __construct(
        protected EvidenceScorer $scorer,
    ) {}

    /**
     * @return array{run_id: string, bodies: int, profiles: int, comparisons: int, candidates: int, refused: int}
     */
    public function run(Incident $incident, string $extractorVersion, bool $withAssignment = true): array
    {
        $bodies = PmCase::where('incident_id', $incident->incident_id)->orderBy('pm_id')->get();
        $profiles = AmFile::where('incident_id', $incident->incident_id)->orderBy('am_id')->get();

        $pmItems = $this->loadItems($bodies->pluck('pm_id')->all(), 'PM', $extractorVersion);
        $amItems = $this->loadItems($profiles->pluck('am_id')->all(), 'AM', $extractorVersion);

        $run = MatchRun::create([
            'run_id' => (string) Str::uuid(),
            'incident_id' => $incident->incident_id,
            'scorer_version' => self::VERSION.'/'.EvidenceScorer::VERSION,
            'extractor_version' => $extractorVersion,
            'config_hash' => substr(hash('sha256', json_encode(EvidenceWeights::CATEGORY)), 0, 16),
            'config' => ['weights' => EvidenceWeights::CATEGORY, 'score_floor' => self::SCORE_FLOOR],
        ]);

        $candidateRows = [];
        $evidenceRows = [];
        $refused = 0;

        foreach ($bodies as $body) {
            $scored = [];

            foreach ($profiles as $profile) {
                $evidence = $this->scorer->score(
                    $body,
                    $profile,
                    $pmItems[$body->pm_id] ?? new RecordItems($body->pm_id),
                    $amItems[$profile->am_id] ?? new RecordItems($profile->am_id),
                );

                $total = EvidenceTotal::of($evidence);

                if ($total->score < self::SCORE_FLOOR) {
                    continue;
                }

                $scored[] = [
                    'am' => $profile,
                    'score' => $total->score,
                    'total' => $total,
                    'evidence' => $evidence,
                ];
            }

            usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
            $scored = array_slice($scored, 0, self::RETAINED_PER_BODY);

            // A body is refused when nothing offered rises above general
            // resemblance — the honest answer for the bodies whose families
            // never filed a report.
            $credible = array_filter($scored, fn ($e) => $e['total']->band !== Candidate::BAND_NONE);

            if ($credible === []) {
                $refused++;
            }

            foreach ($scored as $rank => $entry) {
                $candidateRows[] = [
                    'run_id' => $run->run_id,
                    'pm_id' => $body->pm_id,
                    'am_id' => $entry['am']->am_id,
                    'score' => $entry['total']->score,
                    'rank' => $rank + 1,
                    'confidence_band' => $entry['total']->band,
                    'coverage' => $entry['total']->coverage,
                    'assigned' => false,
                    'recommended_route' => json_encode(ConfirmationRoute::for($body, $entry['am'])),
                ];

                foreach ($entry['evidence'] as $row) {
                    $evidenceRows[] = [
                        'run_id' => $run->run_id,
                        'pm_id' => $body->pm_id,
                        'am_id' => $entry['am']->am_id,
                        'category' => $row['category'],
                        'tier' => $row['tier'],
                        'verdict' => $row['verdict'],
                        'llr' => $row['llr'],
                        'pm_obs_id' => $row['pm_obs_id'],
                        'am_obs_id' => $row['am_obs_id'],
                        'pm_item' => $row['pm_item'] === null ? null : json_encode($row['pm_item'], JSON_UNESCAPED_UNICODE),
                        'am_item' => $row['am_item'] === null ? null : json_encode($row['am_item'], JSON_UNESCAPED_UNICODE),
                        'rationale' => $row['rationale'],
                    ];
                }
            }
        }

        DB::transaction(function () use ($candidateRows, $evidenceRows) {
            foreach (array_chunk($candidateRows, 400) as $chunk) {
                Candidate::insert($chunk);
            }

            foreach (array_chunk($evidenceRows, 400) as $chunk) {
                MatchEvidence::insert($chunk);
            }
        });

        if ($withAssignment) {
            $assigned = app(GlobalAssignment::class)->apply($run);
            $run->assignment_applied = true;
        } else {
            $assigned = 0;
        }

        $stats = [
            'bodies' => $bodies->count(),
            'profiles' => $profiles->count(),
            'comparisons' => $bodies->count() * $profiles->count(),
            'candidates' => count($candidateRows),
            'refused' => $refused,
            'assigned' => $assigned,
        ];

        $run->stats = $stats;
        $run->save();

        return ['run_id' => $run->run_id] + $stats;
    }

    /**
     * Normalised items for a set of records, keyed by record id.
     *
     * A reviewer's correction supersedes the extractor: where a human has
     * confirmed or fixed an item, that version is the one scored.
     *
     * @param  list<string>  $recordIds
     * @return array<string, RecordItems>
     */
    protected function loadItems(array $recordIds, string $recordType, string $extractorVersion): array
    {
        if ($recordIds === []) {
            return [];
        }

        $rows = ObservationNorm::query()
            ->join('observation', 'observation.obs_id', '=', 'observation_norm.obs_id')
            ->where('observation.record_type', $recordType)
            ->whereIn('observation.record_id', $recordIds)
            ->where(fn ($q) => $q
                ->where('observation_norm.extractor_version', $extractorVersion)
                ->orWhereNotNull('observation_norm.reviewed_by'))
            ->select([
                'observation_norm.obs_id',
                'observation_norm.norm_json',
                'observation_norm.confidence',
                'observation_norm.reviewed_by',
                'observation.record_id',
            ])
            ->get();

        $byRecord = [];

        foreach ($rows->groupBy('record_id') as $recordId => $group) {
            // If any item for a form box was human-reviewed, the reviewed set
            // replaces the extractor's reading of that box entirely.
            $reviewedBoxes = $group->whereNotNull('reviewed_by')->pluck('obs_id')->unique()->flip();

            $effective = $group->filter(
                fn ($row) => $row->reviewed_by !== null || ! $reviewedBoxes->has($row->obs_id),
            );

            $byRecord[$recordId] = RecordItems::fromNormRows($recordId, $effective->map(function ($row) {
                $row->norm_json = is_string($row->norm_json) ? json_decode($row->norm_json, true) : $row->norm_json;

                return $row;
            }));
        }

        return $byRecord;
    }
}
