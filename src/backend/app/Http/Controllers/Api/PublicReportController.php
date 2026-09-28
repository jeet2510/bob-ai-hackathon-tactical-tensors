<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Intake\AmFileIntake;
use App\Support\ShareToken;
use App\Support\StagedUploads;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The entire unauthenticated surface of this API: what a family opens via
 * the shareable link (ProfileController::shareLink) hits, with no login of
 * any kind. Every method verifies the same share token before doing
 * anything else — see ShareToken. Nothing here is reachable without a
 * valid, unexpired token for the specific incident in the URL.
 */
class PublicReportController extends Controller
{
    /**
     * Minimal, non-sensitive context for the public page to greet the
     * family with ("You're reporting a missing person for: {incident}")
     * and to fail fast with a friendly message if the link has expired.
     */
    public function context(Request $request, Incident $incident)
    {
        $this->authorizeToken($request, $incident);

        return response()->json([
            'success' => true,
            'incident' => [
                'incident_id' => $incident->incident_id,
                'name' => $incident->name,
                'incident_date' => $incident->incident_date,
            ],
        ]);
    }

    public function store(Request $request, Incident $incident, AmFileIntake $intake)
    {
        $this->authorizeToken($request, $incident);

        $data = $request->validate(AmFileIntake::validationRules());

        $stagedFiles = StagedUploads::resolve($incident->incident_id, [
            $data['recent_photo_ref'] ?? null,
            $data['tattoo_photo_ref'] ?? null,
        ]);

        $am = $intake->store($incident, $data, $stagedFiles, 'family_self_report');

        return response()->json(['success' => true, 'am_id' => $am->am_id], 201);
    }

    public function upload(Request $request, Incident $incident)
    {
        $this->authorizeToken($request, $incident);

        $request->validate(['file' => ['required', 'file', 'max:15360']]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $ref = Str::uuid()->toString().'.'.$extension;

        $file->storeAs("incidents/{$incident->incident_id}/scans", $ref, 'local');

        return response()->json(['success' => true, 'upload_ref' => $ref], 201);
    }

    protected function authorizeToken(Request $request, Incident $incident): void
    {
        $token = $request->query('token') ?? $request->input('token');

        abort_unless(
            ShareToken::verify($token, $incident->incident_id),
            403,
            'This link is invalid or has expired. Ask the coordinator for a new one.',
        );
    }
}
