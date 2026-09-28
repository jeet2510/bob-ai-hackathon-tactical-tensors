<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Intake\AmFileIntake;
use App\Support\ShareToken;
use App\Support\StagedUploads;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Authenticated-side writes for ante-mortem family reports: a coordinator
 * entering a phoned-in report themselves (`store`), and minting the
 * shareable public link a family can use to report one themselves
 * (`shareLink`). The public submission path lives in PublicReportController.
 */
class ProfileController extends Controller
{
    /** How long a minted share link stays valid. */
    protected const LINK_LIFETIME_DAYS = 90;

    public function store(Request $request, Incident $incident, AmFileIntake $intake)
    {
        $data = $request->validate(AmFileIntake::validationRules());

        $stagedFiles = StagedUploads::resolve($incident->incident_id, [
            $data['recent_photo_ref'] ?? null,
            $data['tattoo_photo_ref'] ?? null,
        ]);

        $am = $intake->store($incident, $data, $stagedFiles, 'staff_interview');

        return response()->json(['success' => true, 'am_id' => $am->am_id, 'profile' => $am], 201);
    }

    public function shareLink(Incident $incident)
    {
        $expiresAt = Carbon::now()->addDays(self::LINK_LIFETIME_DAYS);
        $token = ShareToken::make($incident->incident_id, $expiresAt);

        $url = rtrim((string) config('app.frontend_url'), '/')
            ."/report/{$incident->incident_id}?token=".urlencode($token);

        return response()->json([
            'success' => true,
            'url' => $url,
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }
}
