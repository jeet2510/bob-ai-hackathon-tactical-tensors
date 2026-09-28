<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stages a file (a per-row photo for Section F/J, or a PDF/photo about to be
 * sent to Gemini) so it can be referenced by an `upload_ref` before the body
 * form it belongs to is actually submitted.
 *
 * One generic endpoint backs all of these instead of a bespoke handler per
 * section — the staging behaviour (save now, link in on submit) is identical
 * regardless of what the file is for.
 */
class UploadController extends Controller
{
    public function store(Request $request, Incident $incident)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:15360'],
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $ref = Str::uuid()->toString().'.'.$extension;

        // The 'local' disk already roots at storage/app/private.
        $file->storeAs("incidents/{$incident->incident_id}/scans", $ref, 'local');

        return response()->json(['success' => true, 'upload_ref' => $ref], 201);
    }
}
