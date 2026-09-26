<?php

namespace App\Services\Reporting;

use App\Models\AmFile;
use App\Models\Candidate;
use App\Models\Incident;
use App\Models\MatchEvidence;
use App\Models\PmCase;
use App\Models\ReviewDecision;
use Illuminate\Support\Collection;

/**
 * The document handed to the identification commission.
 *
 * It is a working paper, not a determination. Every pairing it lists is a
 * recommendation about which primary-identifier test to run next, and the
 * sections a coordinator most needs are the uncomfortable ones: bodies with no
 * credible candidate, bodies with no viable route to confirming one, and
 * families still waiting with no body to test against.
 */
class ReconciliationReport
{
    public const TOP_CANDIDATES = 3;

    /**
     * @return array<string, mixed>
     */
    public function build(Incident $incident): array
    {
        $run = $incident->latestRun();
        $bodies = PmCase::where('incident_id', $incident->incident_id)->orderBy('pm_id')->get();

        $candidates = $run
            ? Candidate::where('run_id', $run->run_id)->orderBy('rank')->get()->groupBy('pm_id')
            : collect();

        $evidence = $run
            ? MatchEvidence::where('run_id', $run->run_id)
                ->whereIn('verdict', [MatchEvidence::VERDICT_MATCH, MatchEvidence::VERDICT_CONFLICT])
                ->orderByRaw('abs(llr) desc')
                ->get()
                ->groupBy(fn ($e) => $e->pm_id.'|'.$e->am_id)
            : collect();

        $profiles = AmFile::where('incident_id', $incident->incident_id)->get()->keyBy('am_id');

        $decisions = ReviewDecision::query()
            ->whereIn('pm_id', $bodies->pluck('pm_id'))
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->get();

        $current = $decisions->unique('pm_id')->keyBy('pm_id');

        $sections = [];
        $referredProfileIds = [];

        foreach ($bodies as $body) {
            $list = ($candidates[$body->pm_id] ?? collect())
                ->filter(fn ($c) => $c->confidence_band !== Candidate::BAND_NONE)
                ->take(self::TOP_CANDIDATES);

            $decision = $current->get($body->pm_id);

            if ($decision?->decision === ReviewDecision::RECOMMEND_CONFIRM_TEST && $decision->am_id) {
                $referredProfileIds[] = $decision->am_id;
            }

            $sections[] = [
                'body' => [
                    'pm_id' => $body->pm_id,
                    'sex' => $body->sex,
                    'age_estimate' => $body->ageRange(),
                    'height_cm' => $body->height_cm,
                    'body_condition' => $body->body_condition,
                    'found_place' => $body->found_place,
                    'found_at' => $body->found_at?->toDateTimeString(),
                    'dental_status' => $body->dental_status,
                    'dna_status' => $body->dna_status,
                    'print_status' => $body->print_status,
                ],
                'outcome' => $this->outcome($decision, $list),
                'decision' => $decision?->only(['decision', 'am_id', 'reviewer', 'note', 'decided_at']),
                'candidates' => $list->map(fn (Candidate $c) => [
                    'am_id' => $c->am_id,
                    'rank' => $c->rank,
                    'score' => $c->score,
                    'confidence_band' => $c->confidence_band,
                    'coverage' => $c->coverage,
                    'assigned' => $c->assigned,
                    'recommended_route' => $c->recommended_route,
                    'reported_name' => $profiles[$c->am_id]->reported_name ?? null,
                    'reported_by_relation' => $profiles[$c->am_id]->reported_by_relation ?? null,
                    'key_evidence' => ($evidence[$body->pm_id.'|'.$c->am_id] ?? collect())
                        ->take(5)
                        ->map(fn ($e) => [
                            'category' => $e->category,
                            'tier' => $e->tier,
                            'verdict' => $e->verdict,
                            'llr' => $e->llr,
                            'rationale' => $e->rationale,
                        ])->values(),
                ])->values(),
            ];
        }

        $outstanding = $profiles
            ->whereNotIn('am_id', $referredProfileIds)
            ->map(fn (AmFile $p) => [
                'am_id' => $p->am_id,
                'reported_name' => $p->reported_name,
                'sex' => $p->sex,
                'age' => $p->age,
                'reported_by_relation' => $p->reported_by_relation,
                'last_seen_place' => $p->last_seen_place,
                'dna_reference_type' => $p->dna_reference_type,
                'dental_records_available' => $p->dental_records_available,
            ])
            ->values();

        return [
            'incident' => $incident->only(['incident_id', 'name', 'incident_date', 'district', 'synthetic']),
            'generated_at' => now()->toDateTimeString(),
            'run' => $run?->only(['run_id', 'scorer_version', 'extractor_version', 'created_at']),
            'summary' => [
                'bodies' => $bodies->count(),
                'profiles' => $profiles->count(),
                'referred_for_confirmation' => count(array_unique($referredProfileIds)),
                'awaiting_review' => count(array_filter($sections, fn ($s) => $s['outcome'] === 'awaiting_review')),
                'no_credible_candidate' => count(array_filter($sections, fn ($s) => $s['outcome'] === 'no_credible_candidate')),
                'no_confirmation_route' => count(array_filter(
                    $sections,
                    fn ($s) => ($s['candidates'][0]['recommended_route']['route'] ?? null) === null
                        && $s['candidates']->isNotEmpty(),
                )),
                'families_still_waiting' => $outstanding->count(),
            ],
            'sections' => $sections,
            'outstanding_profiles' => $outstanding,
            'disclaimer' => 'Decision support only. Scores are ordinal evidence strength, not probabilities, '
                .'and no entry in this report constitutes an identification. INTERPOL requires a match on a '
                .'primary identifier — fingerprints, dental records or DNA — before remains are released.',
        ];
    }

    /**
     * @param  Collection<int, Candidate>  $candidates
     */
    protected function outcome(?ReviewDecision $decision, $candidates): string
    {
        if ($decision?->decision === ReviewDecision::RECOMMEND_CONFIRM_TEST) {
            return 'referred_for_confirmation';
        }

        if ($decision?->decision === ReviewDecision::NO_CREDIBLE_CANDIDATE) {
            return 'no_credible_candidate';
        }

        if ($candidates->isEmpty()) {
            return 'no_credible_candidate';
        }

        return 'awaiting_review';
    }
}
