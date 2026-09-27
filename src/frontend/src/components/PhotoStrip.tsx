import AuthImage from "./AuthImage";
import type { PhotoRow } from "../types";

const MODALITY_LABEL: Record<string, string> = {
    body_diagram: "Examiner body diagram",
    tattoo_closeup: "Tattoo close-up",
    clothing_flatlay: "Effects and clothing",
    last_seen_photo: "Last-seen photograph",
    tattoo_photo: "Tattoo photograph",
    face_photo_restricted: "Facial photograph",
};

/**
 * Photographic evidence.
 *
 * Two rules are visible in the interface because they are not implementation
 * details, they are the policy: a photograph is never matched directly, and
 * facial photographs are never displayed at all. Visual recognition is
 * unreliable after post-mortem change and is not a primary identifier, so
 * face images exist here only as a labelled absence — a reviewer should know
 * one was taken, and know why they cannot see it.
 */
export default function PhotoStrip({ photos }: { photos: PhotoRow[] }) {
    if (photos.length === 0) {
        return <div className="empty">No photographic evidence attached.</div>;
    }

    return (
        <div className="photo-grid">
            {photos.map((photo) => (
                <figure key={photo.photo_id} className="photo">
                    {photo.restricted ? (
                        <div className="photo-restricted">
                            <strong>Restricted</strong>
                            <span>Facial photographs are never displayed or matched.</span>
                        </div>
                    ) : (
                        <AuthImage src={photo.url ?? ""} alt={MODALITY_LABEL[photo.modality] ?? photo.modality} />
                    )}

                    <figcaption>
                        <div className="photo-title">
                            {MODALITY_LABEL[photo.modality] ?? photo.modality}
                        </div>
                        <div className="faint">
                            {photo.source_type?.replace(/_/g, " ")}
                            {photo.captured_at && ` · ${photo.captured_at.slice(0, 10)}`}
                        </div>

                        {photo.quality_flags.length > 0 && (
                            <div className="photo-flags">
                                {photo.quality_flags.map((flag) => (
                                    <span key={flag} className="chip chip-partial">
                                        {flag.replace(/_/g, " ")}
                                    </span>
                                ))}
                            </div>
                        )}

                        {photo.reliability_note && (
                            <div className="faint photo-note">{photo.reliability_note}</div>
                        )}

                        {photo.sha256 && (
                            <div className="faint mono photo-hash" title="Chain-of-custody hash">
                                sha256 {photo.sha256.slice(0, 12)}…
                            </div>
                        )}
                    </figcaption>
                </figure>
            ))}
        </div>
    );
}
