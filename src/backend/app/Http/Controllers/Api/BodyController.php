<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AmFile;
use App\Models\Candidate;
use App\Models\Incident;
use App\Models\MatchEvidence;
use App\Models\MatchRun;
use App\Models\ObservationNorm;
use App\Models\PhotoEvidence;
use App\Models\PmCase;
use App\Models\ReviewDecision;
use App\Services\Extraction\FormScanCoordinator;
use App\Services\Intake\PmCaseIntake;
use App\Services\Matching\GeminiMatcher;
use App\Support\StagedUploads;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BodyController extends Controller
{
    /**
     * Creates a recovered-body record from the post-mortem intake form.
     *
     * The single write path for the Incident Pipeline: manual entry and both
     * AI-assist panels (scan-pdf, scan-photo) only ever populate the client's
     * form state before this is called — nothing is persisted until a human
     * reviews and submits it.
     */
    public function store(Request $request, Incident $incident)
    {
        $data = $request->validate([
            'pm_id' => ['nullable', 'string', 'max:40'],
            'examiner_name' => ['required', 'string', 'max:255'],
            'examiner_role' => ['required', 'string', 'max:255'],
            'found_at' => ['required', 'date'],
            'found_place' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lon' => ['nullable', 'numeric', 'between:-180,180'],
            'body_condition' => ['required', Rule::in(['Fresh', 'Slight decomp.', 'Moderate decomp.', 'Advanced decomp.', 'Burnt'])],
            'sex' => ['required', Rule::in(['M', 'F'])],
            'age_min' => ['required', 'integer', 'min:0', 'max:120'],
            'age_max' => ['required', 'integer', 'min:0', 'max:120', 'gte:age_min'],
            'height_cm' => ['nullable', 'integer', 'min:30', 'max:250'],
            'build' => ['nullable', 'string', 'max:20'],
            'skin_tone' => ['nullable', 'string', 'max:20'],
            'skin_tone_other' => ['nullable', 'string', 'max:100'],
            'hair_colour' => ['nullable', 'string', 'max:20'],
            'hair_length' => ['nullable', 'string', 'max:20'],
            'eye_colour' => ['nullable', 'string', 'max:20'],
            'facial_hair' => ['nullable', 'string', 'max:30'],
            'dna_status' => ['required', Rule::in(['sample_taken', 'degraded', 'not_collected'])],
            'dental_status' => ['required', Rule::in(['chart_completed', 'not_examined', 'unsuitable'])],
            'print_status' => ['required', Rule::in(['usable', 'unusable', 'not_taken'])],

            'dental_chart' => ['nullable', 'array'],
            'dental_chart.*.tooth' => ['required_with:dental_chart', 'integer', 'min:11', 'max:48'],
            'dental_chart.*.code' => ['required_with:dental_chart', Rule::in(['M', 'F', 'C', 'R'])],

            'distinguishing_features' => ['nullable', 'array', 'max:6'],
            'distinguishing_features.*.type' => ['required_with:distinguishing_features', 'string',
                Rule::in(['tattoo', 'mark', 'scar', 'mole', 'birthmark', 'burn', 'deformity', 'amputation', 'piercing', 'implant', 'other'])],
            'distinguishing_features.*.description' => ['nullable', 'string', 'max:500'],
            'distinguishing_features.*.region' => ['nullable', 'string', 'max:40'],
            'distinguishing_features.*.side' => ['nullable', Rule::in(['L', 'R', 'C'])],
            'distinguishing_features.*.serial_no' => ['nullable', 'string', 'max:120'],
            'distinguishing_features.*.photo_ref' => ['nullable', 'string', 'max:20'],

            'clothing' => ['nullable', 'array', 'max:5'],
            'clothing.*.slot' => ['required_with:clothing', Rule::in(['Headwear', 'Upper body', 'Lower body', 'Footwear', 'Other'])],
            'clothing.*.garment' => ['nullable', 'string', 'max:60'],
            'clothing.*.colour' => ['nullable', 'string', 'max:40'],

            'jewellery_effects' => ['nullable', 'array', 'max:3'],
            'jewellery_effects.*.kind' => ['required_with:jewellery_effects', Rule::in(['jewellery', 'belonging'])],
            'jewellery_effects.*.item' => ['nullable', 'string', 'max:120'],
            'jewellery_effects.*.material_description' => ['nullable', 'string', 'max:255'],

            'id_documents' => ['nullable', 'array'],
            'id_documents.*.document_type' => ['nullable', 'string', 'max:60'],
            'id_documents.*.id_last4' => ['nullable', 'string', 'max:4'],
            'id_documents.*.name_on_document' => ['nullable', 'string', 'max:255'],
            'id_documents.*.note' => ['nullable', 'string', 'max:255'],

            'photo_log' => ['nullable', 'array'],
            'photo_log.*.label' => ['nullable', 'string', 'max:20'],
            'photo_log.*.modality' => ['required_with:photo_log', Rule::in(['Body diagram', 'Tattoo/mark', 'Clothing', 'Face (restr.)', 'Other'])],
            'photo_log.*.view' => ['nullable', 'string', 'max:60'],
            'photo_log.*.quality_flags' => ['nullable', 'array'],
            'photo_log.*.quality_flags.*' => ['string', Rule::in(['Blur', 'Low light', 'Noise', 'None'])],
            'photo_log.*.upload_ref' => ['nullable', 'string', 'max:80'],

            'notes' => ['nullable', 'string', 'max:4000'],
            'signature_note' => ['nullable', 'string', 'max:255'],
            'completed_at' => ['nullable', 'date'],
            'chain_of_custody_hash' => ['nullable', 'string', 'max:120'],
            'scan_source_ref' => ['nullable', 'string', 'max:80'],
            'source' => ['required', Rule::in(['manual', 'pdf_scan', 'live_scan', 'mixed'])],
        ]);

        $stagedFiles = $this->resolveStagedFiles($incident, $data);

        $pm = app(PmCaseIntake::class)->store($incident, $data, $stagedFiles);

        return response()->json(['success' => true, 'pm_id' => $pm->pm_id, 'body' => $pm], 201);
    }

    /**
     * Uploads and reads a scanned/photographed copy of the paper form.
     *
     * Never persists anything — it stages the original for audit and returns
     * a form-shaped JSON payload for the client to prefill and review before
     * the real POST .../bodies call.
     */
    public function scanPdf(Request $request, Incident $incident, FormScanCoordinator $coordinator)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:15360']]);

        $file = $request->file('file');
        $ref = Str::uuid()->toString().'.pdf';
        $file->storeAs("incidents/{$incident->incident_id}/scans", $ref, 'local');

        $result = $coordinator->scanPdf(base64_encode(file_get_contents($file->getRealPath())));

        return response()->json([
            'success' => true,
            'ai_available' => $result['ai_available'],
            'provider' => $result['provider'],
            'upload_ref' => $ref,
            'fields' => $result['fields'],
            'confidence' => $result['confidence'] ?? null,
            'reason' => $result['reason'] ?? null,
        ]);
    }

    /**
     * Uploads and reads a live-scan photograph of a recovered body.
     *
     * Same never-persists contract as scanPdf — only the visually-derivable
     * subset of the form is returned.
     */
    public function scanPhoto(Request $request, Incident $incident, FormScanCoordinator $coordinator)
    {
        $request->validate(['file' => ['required', 'image', 'mimes:jpeg,png,webp,heic', 'max:15360']]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
        $ref = Str::uuid()->toString().'.'.$extension;
        $file->storeAs("incidents/{$incident->incident_id}/scans", $ref, 'local');

        $result = $coordinator->scanPhoto(base64_encode(file_get_contents($file->getRealPath())), (string) $file->getMimeType());

        return response()->json([
            'success' => true,
            'ai_available' => $result['ai_available'],
            'provider' => $result['provider'],
            'upload_ref' => $ref,
            'fields' => $result['fields'],
            'confidence' => $result['confidence'] ?? null,
            'reason' => $result['reason'] ?? null,
        ]);
    }

    /**
     * Maps the upload_ref values referenced anywhere in the submitted form
     * back to the staged file each one points at, so PmCaseIntake can move
     * them into their permanent home. Silently ignores a ref that does not
     * resolve to a real staged file — a missing photo must never block
     * saving the rest of the form.
     *
     * @return array<string, string>
     */
    protected function resolveStagedFiles(Incident $incident, array $data): array
    {
        $refs = collect($data['photo_log'] ?? [])->pluck('upload_ref')->filter()->unique()->all();

        return StagedUploads::resolve($incident->incident_id, $refs);
    }
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
     * Runs Gemini's multimodal refinement of this body's existing
     * rules-based shortlist and persists the result as a new gemini-v1 run.
     * Never runs the deterministic matcher itself, and never affects which
     * run Incident::latestRun() returns.
     */
    public function geminiMatch(Incident $incident, string $pmId, GeminiMatcher $matcher)
    {
        $body = PmCase::where('incident_id', $incident->incident_id)->findOrFail($pmId);

        $result = $matcher->match($incident, $body);

        if (! $result['run_id']) {
            return response()->json([
                'success' => false,
                'ai_available' => $result['ai_available'],
                'run_id' => null,
                'candidates' => [],
                'reason' => $result['reason'],
                'message' => $result['reason'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'ai_available' => true,
            'run_id' => $result['run_id'],
            'summary' => $result['summary'],
            'candidates' => $this->decorateGeminiCandidates($result['candidates'], $body->pm_id),
            'reason' => null,
        ]);
    }

    /**
     * The last Gemini refinement already saved for this body, if any —
     * never calls Gemini itself.
     */
    public function latestGeminiMatch(Incident $incident, string $pmId)
    {
        $body = PmCase::where('incident_id', $incident->incident_id)->findOrFail($pmId);
        $run = $incident->latestGeminiRun($body->pm_id);

        if (! $run) {
            return response()->json(['success' => true, 'ai_available' => true, 'run_id' => null, 'summary' => null, 'candidates' => [], 'reason' => null]);
        }

        $candidates = Candidate::where('run_id', $run->run_id)
            ->where('pm_id', $body->pm_id)
            ->orderBy('rank')
            ->get();

        return response()->json([
            'success' => true,
            'ai_available' => true,
            'run_id' => $run->run_id,
            'summary' => $run->stats['summary'] ?? null,
            'candidates' => $this->decorateGeminiCandidates($candidates, $body->pm_id),
            'reason' => null,
        ]);
    }

    /**
     * Attaches each candidate's profile and a compact summary of the
     * deterministic scorer's own evidence for that pairing — the "matched
     * evidence" a reviewer needs beside Gemini's rationale, without pulling
     * in the full per-category breakdown the Candidates tab already shows.
     *
     * @param  \Illuminate\Support\Collection<int, Candidate>  $candidates
     * @return list<array<string, mixed>>
     */
    protected function decorateGeminiCandidates($candidates, string $pmId): array
    {
        $candidates = collect($candidates);
        $amIds = $candidates->pluck('am_id');

        $profiles = AmFile::whereIn('am_id', $amIds)->get()->keyBy('am_id');

        // Evidence lives on the deterministic run each gemini-v1 run was
        // refined from (candidate.run_id here is the gemini run itself,
        // which never gets its own match_evidence rows — see GeminiMatcher).
        $sourceRunIds = $candidates->pluck('run_id')->unique()
            ->map(fn ($runId) => MatchRun::find($runId)?->config['source_run_id'] ?? null)
            ->filter()
            ->unique();

        $evidenceByAm = MatchEvidence::whereIn('run_id', $sourceRunIds)
            ->where('pm_id', $pmId)
            ->whereIn('am_id', $amIds)
            ->orderByRaw('abs(llr) desc')
            ->get()
            ->groupBy('am_id');

        return $candidates->map(fn (Candidate $c) => [
            'am_id' => $c->am_id,
            'rank' => $c->rank,
            'score' => $c->score,
            'confidence_band' => $c->confidence_band,
            'coverage' => $c->coverage,
            'recommended_route' => $c->recommended_route,
            'ai_rationale' => $c->ai_rationale,
            'ai_visual_notes' => $c->ai_visual_notes,
            'profile' => $profiles->get($c->am_id),
            'evidence_summary' => ($evidenceByAm[$c->am_id] ?? collect())
                ->filter(fn (MatchEvidence $e) => $e->isInformative())
                ->map(fn (MatchEvidence $e) => [
                    'category' => $e->category,
                    'verdict' => $e->verdict,
                    'rationale' => $e->rationale,
                ])
                ->values(),
        ])->values()->all();
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
