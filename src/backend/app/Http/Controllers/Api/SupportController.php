<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AmFile;
use App\Models\Candidate;
use App\Models\Incident;
use App\Models\ObservationNorm;
use App\Models\PhotoEvidence;
use App\Services\Reporting\ReconciliationReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The remaining read endpoints plus the two write paths that are not review
 * decisions: correcting a mis-read item, and serving a photograph.
 */
class SupportController extends Controller
{
    public function profiles(Request $request, Incident $incident)
    {
        $profiles = AmFile::query()
            ->where('incident_id', $incident->incident_id)
            ->when($request->string('search')->isNotEmpty(), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($inner) => $inner
                    ->where('reported_name', 'like', $term)
                    ->orWhere('am_id', 'like', $term)
                    ->orWhere('last_seen_place', 'like', $term));
            })
            ->orderBy('am_id')
            ->get();

        return response()->json(['success' => true, 'profiles' => $profiles]);
    }

    public function profile(Incident $incident, string $amId)
    {
        $profile = AmFile::where('incident_id', $incident->incident_id)->findOrFail($amId);
        $run = $incident->latestRun();

        return response()->json([
            'success' => true,
            'profile' => $profile,
            'observations' => $profile->observations()->orderBy('obs_id')->get(),
            'candidates' => $run
                ? Candidate::where('run_id', $run->run_id)->where('am_id', $amId)
                    ->orderByDesc('score')->limit(5)->get()
                : [],
        ]);
    }

    public function report(Incident $incident, ReconciliationReport $report)
    {
        return response()->json(['success' => true, 'report' => $report->build($incident)]);
    }

    /**
     * The photographic record for the dashboard gallery.
     *
     * Only ever matchable photographs — never a restricted placeholder — the
     * same rule BodyController enforces per-body, applied incident-wide.
     */
    public function photos(Request $request, Incident $incident)
    {
        $photos = $incident->photos()
            ->matchable()
            ->when($request->string('modality')->isNotEmpty(), fn ($q) => $q->where('modality', $request->string('modality')))
            ->orderByDesc('captured_at')
            ->limit(min((int) $request->integer('limit', 60), 200))
            ->get()
            ->map(fn (PhotoEvidence $p) => [
                'photo_id' => $p->photo_id,
                'record_type' => $p->record_type,
                'record_id' => $p->record_id,
                'modality' => $p->modality,
                'view' => $p->view,
                'captured_at' => $p->captured_at?->toDateTimeString(),
                'source_type' => $p->source_type,
                'quality_flags' => $p->qualityFlagList(),
                'url' => "/api/photos/{$p->photo_id}/file",
            ]);

        return response()->json(['success' => true, 'photos' => $photos]);
    }

    /**
     * The global one-to-one solution, beside the per-body ranking.
     *
     * Where the two disagree is exactly where a look-alike cluster was
     * resolved, so those rows are flagged rather than buried.
     */
    public function assignment(Incident $incident)
    {
        $run = $incident->latestRun();

        if (! $run) {
            return response()->json(['success' => true, 'rows' => [], 'run' => null]);
        }

        $candidates = Candidate::where('run_id', $run->run_id)
            ->where(fn ($q) => $q->where('assigned', true)->orWhere('rank', 1))
            ->get()
            ->groupBy('pm_id');

        $names = AmFile::where('incident_id', $incident->incident_id)->pluck('reported_name', 'am_id');

        $rows = $candidates->map(function ($group, $pmId) use ($names) {
            $assigned = $group->firstWhere('assigned', true);
            $ranked = $group->firstWhere('rank', 1);

            return [
                'pm_id' => $pmId,
                'ranked_first' => $ranked ? [
                    'am_id' => $ranked->am_id,
                    'reported_name' => $names[$ranked->am_id] ?? null,
                    'score' => $ranked->score,
                    'confidence_band' => $ranked->confidence_band,
                ] : null,
                'assigned' => $assigned ? [
                    'am_id' => $assigned->am_id,
                    'reported_name' => $names[$assigned->am_id] ?? null,
                    'score' => $assigned->score,
                    'confidence_band' => $assigned->confidence_band,
                ] : null,
                'differs' => $assigned && $ranked && $assigned->am_id !== $ranked->am_id,
            ];
        })->values()->sortByDesc('differs')->values();

        return response()->json(['success' => true, 'run' => $run, 'rows' => $rows]);
    }

    /**
     * A reviewer correcting or confirming an item the extractor read out of a
     * form box. The correction supersedes the extractor everywhere downstream.
     */
    public function reviewItem(Request $request, ObservationNorm $item)
    {
        $data = $request->validate([
            'item' => ['required', 'array'],
            'item.category' => ['required', 'string'],
            'reviewer' => ['nullable', 'string', 'max:120'],
        ]);

        // Take the item from the raw input, not from the validated set:
        // validate() returns only the keys it was given rules for, which would
        // silently discard every field of the correction except `category`.
        // The item schema is open by design — categories carry different
        // fields — so it is validated as a shape, then stored whole.
        $item->update([
            'norm_json' => $request->input('item'),
            'reviewed_by' => $data['reviewer'] ?? (Auth::user()?->name ?? 'reviewer'),
            'reviewed_at' => now(),
            'confidence' => 1.0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item corrected. Re-run matching for it to affect candidate rankings.',
            'item' => $item->fresh(),
        ]);
    }

    /**
     * Serves a photograph.
     *
     * Restricted placeholders are refused here as well as hidden in the UI:
     * an access-control rule that only exists in the interface is not an
     * access-control rule.
     */
    public function photo(PhotoEvidence $photo)
    {
        abort_unless($photo->isMatchable(), 403, 'This photograph is display-restricted and is never served or matched.');

        // file_path is recorded relative to the dataset root ("images/pm/...").
        $root = rtrim((string) config('dvi.data_path'), '/');
        $path = realpath($root.'/'.ltrim((string) $photo->file_path, '/'));

        // Defence in depth: never serve anything outside the dataset, whatever
        // a path column happens to contain.
        abort_unless($path !== false && str_starts_with($path, (string) realpath($root)), 404);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=3600']);
    }
}
