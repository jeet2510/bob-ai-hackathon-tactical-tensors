import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../api/client";
import AuthImage from "./AuthImage";
import type { GalleryPhoto } from "../types";

const MODALITY_LABEL: Record<string, string> = {
    body_diagram: "Examiner diagram",
    tattoo_closeup: "Tattoo close-up",
    clothing_flatlay: "Effects & clothing",
    last_seen_photo: "Last-seen photograph",
    tattoo_photo: "Tattoo photograph",
};

const FILTERS = ["all", "body_diagram", "clothing_flatlay", "last_seen_photo", "tattoo_photo", "tattoo_closeup"];

export default function PhotoGallery({ incidentId }: { incidentId: string }) {
    const [photos, setPhotos] = useState<GalleryPhoto[]>([]);
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState("all");

    useEffect(() => {
        let cancelled = false;
        setLoading(true);

        api.get(`/incidents/${incidentId}/photos`, {
            params: { limit: 36, ...(filter !== "all" ? { modality: filter } : {}) },
        })
            .then(({ data }) => {
                if (!cancelled) setPhotos(data.photos);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [incidentId, filter]);

    return (
        <section className="card gallery-card">
            <div className="gallery-head">
                <div>
                    <h3>Photographic record</h3>
                    <p className="muted">
                        Recovery-scene and effects photography from across the incident. Facial
                        photographs are never shown — see each body's own record for why.
                    </p>
                </div>

                <div className="gallery-filters" role="tablist" aria-label="Filter photographs by type">
                    {FILTERS.map((f) => (
                        <button
                            key={f}
                            type="button"
                            role="tab"
                            aria-selected={filter === f}
                            className={`gallery-filter${filter === f ? " active" : ""}`}
                            onClick={() => setFilter(f)}
                        >
                            {f === "all" ? "All" : MODALITY_LABEL[f]}
                        </button>
                    ))}
                </div>
            </div>

            {loading ? (
                <div className="gallery-grid">
                    {Array.from({ length: 8 }).map((_, i) => (
                        <div key={i} className="auth-image-skeleton gallery-thumb" aria-hidden="true" />
                    ))}
                </div>
            ) : photos.length === 0 ? (
                <div className="empty">No photographs of this type recorded.</div>
            ) : (
                <div className="gallery-grid">
                    {photos.map((photo) => (
                        <Link
                            key={photo.photo_id}
                            className="gallery-thumb"
                            to={
                                photo.record_type === "PM"
                                    ? `/incidents/${incidentId}/bodies/${photo.record_id}`
                                    : `/incidents/${incidentId}/profiles`
                            }
                            title={`${MODALITY_LABEL[photo.modality] ?? photo.modality} · ${photo.record_id}`}
                        >
                            <AuthImage
                                src={photo.url}
                                alt={`${MODALITY_LABEL[photo.modality] ?? photo.modality} for ${photo.record_id}`}
                            />
                            <span className="gallery-thumb-tag">
                                <span className="mono">{photo.record_id}</span>
                                <span>{MODALITY_LABEL[photo.modality] ?? photo.modality}</span>
                            </span>
                        </Link>
                    ))}
                </div>
            )}

            {!loading && photos.length > 0 && (
                <p className="faint" style={{ marginTop: 12 }}>
                    Showing {photos.length} of the incident's matchable photographs.
                </p>
            )}
        </section>
    );
}
