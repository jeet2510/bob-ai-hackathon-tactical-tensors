<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AmFile;
use App\Models\Candidate;
use App\Models\Incident;
use App\Models\MatchEvidence;
use App\Models\ObservationNorm;
use App\Models\PhotoEvidence;
use App\Models\PmCase;
use App\Models\ReviewDecision;
use Illuminate\Http\Request;

class BodyController extends Controller
{
    /**
     * The triage list: every recovered body with its strongest candidate.
     */
    public function index(Request $request, Incident $incident)
    {
        $run = $incident->latestRun();

        $bodies = PmCase::query()
            ->where('incident_id', $incident->incident_id)
            ->when($request->string('condition')->isNotEmpty(),
                fn ($q) => $q->where('body_condition', $request->string('condition')))
            ->when($request->string('search')->isNotEmpty(), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($inner) => $inner
                    ->where('pm_id', 'like', $term)
                    ->orWhere('found_place', 'like', $term));
            })
            ->orderBy('pm_id')
            ->get();

        $top = $run
            ? Candidate::where('run_id', $run->run_id)->where('rank', 1)->get()->keyBy('pm_id')
            : collect();

        $names = AmFile::whereIn('am_id', $top->pluck('am_id'))->pluck('reported_name', 'am_id');

        $decisions = ReviewDecision::query()
            ->whereIn('pm_id', $bodies->pluck('pm_id'))
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->get()
            ->unique('pm_id')
            ->keyBy('pm_id');

        $rows = $bodies->map(function (PmCase $body) use ($top, $names, $decisions) {
            $candidate = $top->get($body->pm_id);

            return [
                'pm_id' => $body->pm_id,
                'sex' => $body->sex,
                'age_estimate' => $body->ageRange(),
                'height_cm' => $body->height_cm,
                'body_condition' => $body->body_condition,
                'found_place' => $body->found_place,
                'found_at' => $body->found_at?->toDateTimeString(),
                'lat' => $body->lat,
                'lon' => $body->lon,
                'degraded' => $body->isDegraded(),
                'dental_status' => $body->dental_status,
                'dna_status' => $body->dna_status,
                'print_status' => $body->print_status,
                'top_candidate' => $candidate ? [
                    'am_id' => $candidate->am_id,
                    'reported_name' => $names[$candidate->am_id] ?? null,
                    'score' => $candidate->score,
                    'confidence_band' => $candidate->confidence_band,
                    'coverage' => $candidate->coverage,
                    'assigned' => $candidate->assigned,
                    'recommended_route' => $candidate->recommended_route,
                ] : null,
                'decision' => $decisions->get($body->pm_id)?->only(['decision', 'reviewer', 'note', 'decided_at']),
            ];
        });

        if ($request->string('band')->isNotEmpty()) {
            $band = (string) $request->string('band');

            $rows = $rows->filter(function ($row) use ($band) {
                $actual = $row['top_candidate']['confidence_band'] ?? Candidate::BAND_NONE;

                return $actual === $band;
            })->values();
        }

        return response()->json(['success' => true, 'run' => $run, 'bodies' => $rows->values()]);
    }

    /**
     * One body, with everything a reviewer needs to decide: the raw form
     * boxes, the photographs, and the ranked candidates with their evidence.
     */
    public function show(Incident $incident, string $pmId)
    {
        $body = PmCase::where('incident_id', $incident->incident_id)->findOrFail($pmId);
        $run = $incident->latestRun();

        $candidates = collect();

        if ($run) {
            $rows = Candidate::where('run_id', $run->run_id)
                ->where('pm_id', $body->pm_id)
                ->orderBy('rank')
                ->limit(3)
                ->get();

            $profiles = AmFile::whereIn('am_id', $rows->pluck('am_id'))->get()->keyBy('am_id');

            $evidence = MatchEvidence::where('run_id', $run->run_id)
                ->where('pm_id', $body->pm_id)
                ->whereIn('am_id', $rows->pluck('am_id'))
                ->orderByRaw('abs(llr) desc')
                ->get()
                ->groupBy('am_id');

            $candidates = $rows->map(fn (Candidate $c) => [
                'am_id' => $c->am_id,
                'rank' => $c->rank,
                'score' => $c->score,
                'confidence_band' => $c->confidence_band,
                'coverage' => $c->coverage,
                'assigned' => $c->assigned,
                'recommended_route' => $c->recommended_route,
                'profile' => $profiles->get($c->am_id),
                'evidence' => ($evidence[$c->am_id] ?? collect())->values(),
            ]);
        }

        return response()->json([
            'success' => true,
            'body' => $body,
            'observations' => $body->observations()->orderBy('obs_id')->get()
                ->map(fn ($o) => $o->toArray() + [
                    'items' => $this->itemsFor($o->obs_id, $run?->extractor_version),
                ]),
            'photos' => $this->photos($body->pm_id, 'PM'),
            'candidates' => $candidates,
            'decisions' => ReviewDecision::where('pm_id', $body->pm_id)
                ->orderByDesc('decided_at')->orderByDesc('id')->get(),
            'run' => $run,
        ]);
    }

    /**
     * Normalised items for one form box, with the reviewer's corrections
     * taking precedence over the extractor's reading.
     *
     * @return list<array<string, mixed>>
     */
    protected function itemsFor(string $obsId, ?string $extractorVersion): array
    {
        $rows = ObservationNorm::where('obs_id', $obsId)
            ->where(fn ($q) => $q
                ->where('extractor_version', $extractorVersion ?? '')
                ->orWhereNotNull('reviewed_by'))
            ->orderBy('item_index')
            ->get();

        $reviewed = $rows->whereNotNull('reviewed_by');

        return ($reviewed->isNotEmpty() ? $reviewed : $rows)
            ->map(fn ($r) => [
                'id' => $r->id,
                'item' => $r->norm_json,
                'confidence' => $r->confidence,
                'extractor_version' => $r->extractor_version,
                'reviewed_by' => $r->reviewed_by,
            ])
            ->values()
            ->all();
    }

    /**
     * Photographs attached to a record.
     *
     * Restricted face placeholders are returned so the interface can show that
     * a photograph exists and is deliberately withheld — that is a meaningful
     * thing for a reviewer to know — but they carry no file and are flagged
     * as never matchable.
     *
     * @return list<array<string, mixed>>
     */
    protected function photos(string $recordId, string $recordType): array
    {
        return PhotoEvidence::where('record_type', $recordType)
            ->where('record_id', $recordId)
            ->orderBy('photo_id')
            ->get()
            ->map(fn (PhotoEvidence $p) => [
                'photo_id' => $p->photo_id,
                'modality' => $p->modality,
                'view' => $p->view,
                'captured_at' => $p->captured_at?->toDateTimeString(),
                'source_type' => $p->source_type,
                'quality_flags' => $p->qualityFlagList(),
                'use_policy' => $p->use_policy,
                'restricted' => ! $p->isMatchable(),
                'reliability_note' => $p->reliability_note,
                'sha256' => $p->sha256,
                'url' => $p->isMatchable() ? "/api/photos/{$p->photo_id}/file" : null,
            ])
            ->all();
    }
}
