<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\PmCase;
use App\Models\ReviewDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Records what a reviewer concluded.
 *
 * Append-only by design: there is no update or delete route, because the
 * history of who concluded what, and when, is part of the evidence. Changing
 * your mind adds a row.
 */
class ReviewController extends Controller
{
    public function store(Request $request, Incident $incident, string $pmId)
    {
        $body = PmCase::where('incident_id', $incident->incident_id)->findOrFail($pmId);

        $data = $request->validate([
            'decision' => ['required', Rule::in(ReviewDecision::DECISIONS)],
            'am_id' => ['nullable', 'string', 'exists:am_file,am_id'],
            'reviewer' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string'],
        ]);

        // A conclusion about a pairing needs a pairing; a refusal must not
        // carry one, or the record would contradict itself.
        if ($data['decision'] === ReviewDecision::NO_CREDIBLE_CANDIDATE) {
            $data['am_id'] = null;
        } elseif (blank($data['am_id'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'An ante-mortem file is required for this decision.',
                'errors' => ['am_id' => ['Select the candidate this decision applies to.']],
            ], 422);
        }

        $decision = ReviewDecision::create([
            'pm_id' => $body->pm_id,
            'am_id' => $data['am_id'] ?? null,
            'run_id' => $incident->latestRun()?->run_id,
            'reviewer' => $data['reviewer'] ?? 'identification commission',
            'user_id' => Auth::id(),
            'decision' => $data['decision'],
            'note' => $data['note'] ?? null,
            'decided_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $this->confirmationMessage($decision),
            'decision' => $decision,
        ], 201);
    }

    public function index(Incident $incident, string $pmId)
    {
        return response()->json([
            'success' => true,
            'decisions' => ReviewDecision::where('pm_id', $pmId)
                ->orderByDesc('decided_at')->orderByDesc('id')->get(),
        ]);
    }

    /**
     * The whole incident's decision log — the audit trail view.
     */
    public function audit(Incident $incident)
    {
        return response()->json([
            'success' => true,
            'decisions' => ReviewDecision::query()
                ->whereIn('pm_id', PmCase::where('incident_id', $incident->incident_id)->select('pm_id'))
                ->orderByDesc('decided_at')->orderByDesc('id')
                ->limit(500)
                ->get(),
        ]);
    }

    protected function confirmationMessage(ReviewDecision $decision): string
    {
        return match ($decision->decision) {
            ReviewDecision::RECOMMEND_CONFIRM_TEST => 'Recorded: referred for primary-identifier confirmation. No identification is asserted until that test returns.',
            ReviewDecision::REJECT => 'Recorded: pairing ruled out.',
            ReviewDecision::DEFER => 'Recorded: deferred pending further evidence.',
            default => 'Recorded: no credible candidate for this body.',
        };
    }
}
