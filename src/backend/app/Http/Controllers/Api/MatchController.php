
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\MatchCandidate;
use App\Models\PostMortemRecord;
use App\Services\Matching\MatchingService;
use App\Services\Reporting\ReconciliationReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MatchController extends Controller
{
    public function __construct(
        protected MatchingService $matching,
    ) {}

    /**
     * Re-run the full cross-reference for an incident.
     */
    public function run(Incident $incident)
    {
        $stats = $this->matching->runForIncident($incident);

        return response()->json([
            'success' => true,
            'message' => sprintf(
                '%d bodies cross-referenced against %d profiles (%s comparisons).',
                $stats['bodies'],
                $stats['profiles'],
                number_format($stats['comparisons']),
            ),
            'stats' => $stats,
        ]);
    }

    /**
     * Top candidates for one unidentified body.
     */
    public function candidates(Request $request, Incident $incident, PostMortemRecord $record)
    {
        abort_unless($record->incident_id === $incident->id, 404);

        $limit = (int) $request->integer('limit', ReconciliationReportService::TOP_CANDIDATES);

        $candidates = $record->matchCandidates()
            ->with('anteMortemProfile')
            ->where('status', '!=', 'rejected')
            ->orderBy('rank')
            ->take(max(1, min($limit, MatchingService::RETAINED_PER_BODY)))
            ->get();

        return response()->json([
            'success' => true,
            'record' => $record,
            'candidates' => $candidates,
        ]);
    }

    public function show(Incident $incident, MatchCandidate $match)
    {
        abort_unless($match->incident_id === $incident->id, 404);

        return response()->json([
            'success' => true,
            'match' => $match->load(['anteMortemProfile', 'postMortemRecord', 'reviewer:id,name']),
        ]);
    }

    /**
     * Record a forensic officer's confirmation of a pairing.
     */
    public function confirm(Request $request, Incident $incident, MatchCandidate $match)
    {
        abort_unless($match->incident_id === $incident->id, 404);

        $data = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($match, $data) {
            $match->update([
                'status' => 'confirmed',
                'review_notes' => $data['review_notes'] ?? null,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);

            // A body has exactly one identity: every other live candidate for
            // this body, and for the matched profile, is ruled out.
            MatchCandidate::query()
                ->where('id', '!=', $match->id)
                ->where(fn ($query) => $query
                    ->where('post_mortem_record_id', $match->post_mortem_record_id)
                    ->orWhere('ante_mortem_profile_id', $match->ante_mortem_profile_id))
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'review_notes' => 'Superseded by a confirmed identification.',
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                    'updated_by' => Auth::id(),
                ]);

            $match->postMortemRecord?->update(['status' => 'identified']);
            $match->anteMortemProfile?->update(['status' => 'matched']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Match confirmed. Remaining candidates for this body were ruled out.',
            'match' => $match->fresh(['anteMortemProfile', 'postMortemRecord']),
        ]);
    }

    public function reject(Request $request, Incident $incident, MatchCandidate $match)
    {
        abort_unless($match->incident_id === $incident->id, 404);

        $data = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        $match->update([
            'status' => 'rejected',
            'review_notes' => $data['review_notes'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Match rejected.',
            'match' => $match->fresh(),
        ]);
    }

    /**
     * Undo a review decision and return the pairing to the pending queue.
     */
    public function reset(Incident $incident, MatchCandidate $match)
    {
        abort_unless($match->incident_id === $incident->id, 404);

        $wasConfirmed = $match->status === 'confirmed';

        $match->update([
            'status' => 'pending',
            'review_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        if ($wasConfirmed) {
            $match->postMortemRecord?->update(['status' => 'unidentified']);
            $match->anteMortemProfile?->update(['status' => 'open']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Review decision cleared.',
            'match' => $match->fresh(),
        ]);
    }

    /**
     * Incident-wide review queue, strongest pending pairings first.
     */
    public function queue(Request $request, Incident $incident)
    {
        $candidates = MatchCandidate::query()
            ->where('incident_id', $incident->id)
            ->when($request->string('status')->isNotEmpty(),
                fn ($query) => $query->where('status', $request->string('status')),
                fn ($query) => $query->where('status', 'pending'))
            ->when($request->string('confidence_band')->isNotEmpty(),
                fn ($query) => $query->where('confidence_band', $request->string('confidence_band')))
            ->with(['anteMortemProfile:id,reference_code,full_name,sex,age_years', 'postMortemRecord:id,body_tag,sex,recovery_location'])
            ->orderByDesc('score')
            ->take(100)
            ->get();

        return response()->json([
            'success' => true,
            'candidates' => $candidates,
        ]);
    }
}
