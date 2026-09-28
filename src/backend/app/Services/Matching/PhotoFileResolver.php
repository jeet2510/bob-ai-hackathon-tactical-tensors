<?php

namespace App\Services\Matching;

use App\Models\PhotoEvidence;

/**
 * Resolves a matchable photo's file_path (recorded relative to one of two
 * possible roots — the seeded dataset, or this app's own private storage for
 * anything uploaded through the Incident Pipeline) to an absolute path on
 * disk, with the same defence-in-depth containment check every photo read in
 * this app must have: never resolve outside either root, whatever a path
 * column happens to contain.
 *
 * Shared by SupportController::photo() (serving a photo to the browser) and
 * GeminiMatcher (reading photo bytes to send to the Gemini API) — one place
 * that knows the two roots and the traversal guard.
 */
class PhotoFileResolver
{
    /**
     * @return array{path: string, mime: string}|null
     */
    public function resolve(PhotoEvidence $photo): ?array
    {
        if (! $photo->isMatchable()) {
            return null;
        }

        $relative = ltrim((string) $photo->file_path, '/');

        $roots = [storage_path('app/private'), rtrim((string) config('dvi.data_path'), '/')];

        foreach ($roots as $root) {
            $path = realpath($root.'/'.$relative);

            if ($path !== false && str_starts_with($path, (string) realpath($root)) && is_file($path)) {
                return ['path' => $path, 'mime' => mime_content_type($path) ?: 'application/octet-stream'];
            }
        }

        return null;
    }
}
