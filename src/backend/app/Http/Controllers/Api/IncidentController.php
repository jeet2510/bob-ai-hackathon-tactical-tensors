<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Incident;
use App\Models\MatchRun;
use App\Models\Observation;
use App\Models\PhotoEvidence;
use App\Models\PmCase;
use App\Models\ReviewDecision;
use Illuminate\Support\Facades\DB;

class IncidentController extends Controller
{
    public function index()
    {
        $incidents = Incident::query()
            ->withCount(['pmCases', 'amFiles'])
            ->orderByDesc('incident_date')
            ->get();

        return response()->json(['success' => true, 'incidents' => $incidents]);
    }

    public function show(Incident $incident)
    {
        $run = $incident->latestRun();

        return response()->json([
            'success' => true,
            'incident' => $incident->loadCount(['pmCases', 'amFiles']),
            'run' => $run,
            'stats' => $this->stats($incident, $run),
            'languages' => Observation::query()
                ->selectRaw('lang, count(*) as total')
                ->groupBy('lang')
                ->pluck('total', 'lang'),
            'breakdowns' => $this->breakdowns($incident),
        ]);
    }

    /**
     * Everything the dashboard's charts read: case composition, primary
     * identifier readiness, recovery timeline and the photographic record.
     * Grouped counts only — never individual rows, so this stays cheap.
     *
     * @return array<string, mixed>
     */
    protected function breakdowns(Incident $incident): array
    {
        $pm = PmCase::where('incident_id', $incident->incident_id);

        $photosByModality = $incident->photos()
            ->matchable()
            ->selectRaw('modality, count(*) as total')
            ->groupBy('modality')
            ->pluck('total', 'modality');

        return [
            'body_condition' => (clone $pm)->whereNotNull('body_condition')
                ->selectRaw('body_condition as label, count(*) as total')
                ->groupBy('body_condition')->orderByDesc('total')
                ->pluck('total', 'label'),
            'sex' => (clone $pm)->whereNotNull('sex')
                ->selectRaw('sex as label, count(*) as total')
                ->groupBy('sex')
                ->pluck('total', 'label'),
            'dna_status' => (clone $pm)->whereNotNull('dna_status')
                ->selectRaw('dna_status as label, count(*) as total')
                ->groupBy('dna_status')
                ->pluck('total', 'label'),
            'dental_status' => (clone $pm)->whereNotNull('dental_status')
                ->selectRaw('dental_status as label, count(*) as total')
                ->groupBy('dental_status')
                ->pluck('total', 'label'),
            'print_status' => (clone $pm)->whereNotNull('print_status')
                ->selectRaw('print_status as label, count(*) as total')
                ->groupBy('print_status')
                ->pluck('total', 'label'),
            'recovered_by_day' => (clone $pm)
                ->selectRaw('date(found_at) as day, count(*) as total')
                ->groupBy('day')->orderBy('day')
                ->pluck('total', 'day'),
            'photos_by_modality' => $photosByModality,
            'photos_matchable' => $photosByModality->sum(),
            'photos_restricted' => $incident->photos()
                ->where('use_policy', PhotoEvidence::POLICY_RESTRICTED)->count(),
        ];
    }

    /**
     * Headline counters for the command board.
     *
     * Deliberately includes the two numbers a coordinator most needs and that
     * a ranking-only tool would never show: how many bodies have no credible
     * candidate at all, and how many have no viable route to confirming one.
     *
     * @return array<string, mixed>
     */
    protected function stats(Incident $incident, ?MatchRun $run): array
    {
        $bodies = PmCase::where('incident_id', $incident->incident_id)->count();

        if (! $run) {
            return [
                'bodies' => $bodies,
                'profiles' => $incident->amFiles()->count(),
                'matched' => false,
            ];
        }

        $byBand = Candidate::where('run_id', $run->run_id)
            ->where('rank', 1)
            ->groupBy('confidence_band')
            ->select('confidence_band', DB::raw('count(*) as total'))
            ->pluck('total', 'confidence_band');

        $withCandidate = Candidate::where('run_id', $run->run_id)
            ->where('confidence_band', '!=', Candidate::BAND_NONE)
            ->distinct()
            ->count('pm_id');

        $decided = ReviewDecision::query()
            ->whereIn('pm_id', PmCase::where('incident_id', $incident->incident_id)->select('pm_id'))
            ->distinct()
            ->count('pm_id');

        // A body with no primary-identifier route needs a sample collected
        // before any amount of comparison can help it.
        $noRoute = Candidate::where('run_id', $run->run_id)
            ->where('rank', 1)
            ->get()
            ->filter(fn ($c) => ($c->recommended_route['route'] ?? null) === null)
            ->count();

        return [
            'bodies' => $bodies,
            'profiles' => $incident->amFiles()->count(),
            'matched' => true,
            'with_credible_candidate' => $withCandidate,
            'no_credible_candidate' => $bodies - $withCandidate,
            'high' => (int) ($byBand[Candidate::BAND_HIGH] ?? 0),
            'moderate' => (int) ($byBand[Candidate::BAND_MODERATE] ?? 0),
            'low' => (int) ($byBand[Candidate::BAND_LOW] ?? 0),
            'reviewed' => $decided,
            'awaiting_review' => $bodies - $decided,
            'no_confirmation_route' => $noRoute,
            'assigned' => Candidate::where('run_id', $run->run_id)->where('assigned', true)->count(),
        ];
    }
}
