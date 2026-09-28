<?php

namespace App\Support;

/**
 * Resolves upload_ref values (from UploadController / the public upload
 * endpoint) back to the staged file each one points at, so an intake
 * service can move them into their permanent home. Shared by every
 * controller that accepts a submitted form referencing staged uploads
 * (BodyController, ProfileController, PublicReportController) so the
 * path-safety logic exists in exactly one place.
 */
class StagedUploads
{
    /**
     * Silently ignores a ref that does not resolve to a real staged file —
     * a missing photo must never block saving the rest of a form.
     *
     * @param  list<string|null>  $refs
     * @return array<string, string> upload_ref => absolute path
     */
    public static function resolve(string $incidentId, array $refs): array
    {
        $root = storage_path("app/private/incidents/{$incidentId}/scans");
        $resolved = [];

        foreach (array_filter($refs) as $ref) {
            // Refs are UUID.ext, generated only by our own upload endpoints —
            // still resolved through basename() so nothing outside this
            // incident's scan directory can ever be reached.
            $path = $root.'/'.basename((string) $ref);

            if (is_file($path)) {
                $resolved[$ref] = $path;
            }
        }

        return $resolved;
    }
}
