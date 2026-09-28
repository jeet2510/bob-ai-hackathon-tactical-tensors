import { useEffect, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import api from "../../api/client";
import { getShareLink } from "../../api/reports";
import { toApiError } from "../../api/errors";
import { useIncident } from "../IncidentLayout";
import type { AmFile } from "../../types";

export default function Profiles() {
    const { incident } = useIncident();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();

    const [profiles, setProfiles] = useState<AmFile[]>([]);
    // A DVI Assistant "Open AM-…" reference deep-links here with ?q= so the
    // list is already filtered to that one profile on arrival.
    const [search, setSearch] = useState(searchParams.get("q") ?? "");
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [shareUrl, setShareUrl] = useState("");
    const [shareExpires, setShareExpires] = useState("");
    const [shareBusy, setShareBusy] = useState(false);
    const [shareError, setShareError] = useState("");
    const [copied, setCopied] = useState(false);

    async function handleGetShareLink() {
        setShareBusy(true);
        setShareError("");

        try {
            const { data } = await getShareLink(incident.incident_id);
            setShareUrl(data.url);
            setShareExpires(data.expires_at.slice(0, 10));
        } catch (caught) {
            setShareError(toApiError(caught, "Could not create a shareable link.").message);
        } finally {
            setShareBusy(false);
        }
    }

    async function handleShare() {
        if ("share" in navigator) {
            try {
                await navigator.share({ title: "Report a missing person", url: shareUrl });
                return;
            } catch {
                // User cancelled the native share sheet — fall through to copy.
            }
        }

        await navigator.clipboard.writeText(shareUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    useEffect(() => {
        let cancelled = false;

        const timer = setTimeout(() => {
            api.get(`/incidents/${incident.incident_id}/profiles`, {
                params: search ? { search } : {},
            })
                .then(({ data }) => {
                    if (!cancelled) setProfiles(data.profiles);
                })
                .catch((caught) => {
                    if (!cancelled) setError(toApiError(caught, "Could not load reports.").message);
                })
                .finally(() => {
                    if (!cancelled) setLoading(false);
                });
        }, search ? 250 : 0);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [incident.incident_id, search]);

    return (
        <>
            <div className="page-head">
                <div>
                    <h2>Family reports</h2>
                    <p className="muted">
                        Ante-mortem files. Reported names are shown to reviewers but are never used
                        in scoring — a name on a form is not evidence about a body.
                    </p>
                </div>
                <div className="btn-row">
                    <button className="btn" onClick={handleGetShareLink} disabled={shareBusy}>
                        {shareBusy ? "Creating link…" : "Get shareable family-report link"}
                    </button>
                    <Link className="btn btn-primary" to={`/incidents/${incident.incident_id}/profiles/new`}>
                        + New family report
                    </Link>
                </div>
            </div>

            {shareError && <div className="error-banner">{shareError}</div>}

            {shareUrl && (
                <div className="card share-link-card">
                    <p className="muted">
                        Anyone with this link can report a missing person for this incident — no login
                        needed. It expires on {shareExpires}.
                    </p>
                    <div className="share-link-row">
                        <input className="mono" readOnly value={shareUrl} onFocus={(e) => e.target.select()} />
                        <button type="button" className="btn btn-sm" onClick={handleShare}>
                            {copied ? "Copied!" : "share" in navigator ? "Share" : "Copy"}
                        </button>
                    </div>
                </div>
            )}

            {error && <div className="error-banner">{error}</div>}

            <div className="toolbar">
                <input
                    type="search"
                    placeholder="Search by name, reference or place last seen…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </div>

            {loading ? (
                <div className="empty">Loading…</div>
            ) : (
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Reported name</th>
                                <th>Sex / age</th>
                                <th>Height as reported</th>
                                <th>Reported by</th>
                                <th>Last seen</th>
                                <th>Reference samples</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {profiles.map((profile) => (
                                <tr
                                    key={profile.am_id}
                                    className="row-clickable"
                                    onClick={() => navigate(`/incidents/${incident.incident_id}/profiles/${profile.am_id}`)}
                                >
                                    <td className="mono">{profile.am_id}</td>
                                    <td>{profile.reported_name}</td>
                                    <td>
                                        {profile.sex ?? "—"}
                                        {profile.age !== null && ` · ${profile.age}`}
                                    </td>
                                    <td>
                                        {profile.height_text ?? "—"}
                                        {profile.height_cm_reported && (
                                            <div className="faint">{profile.height_cm_reported} cm</div>
                                        )}
                                    </td>
                                    <td>{profile.reported_by_relation ?? "—"}</td>
                                    <td>
                                        {profile.last_seen_place ?? "—"}
                                        <div className="faint">{profile.last_seen_at?.slice(0, 16)}</div>
                                    </td>
                                    <td>
                                        <div className="feature-chips">
                                            {profile.dental_records_available && (
                                                <span className="chip chip-match">dental</span>
                                            )}
                                            {profile.prints_on_file && (
                                                <span className="chip chip-match">prints</span>
                                            )}
                                            {profile.dna_reference_type && (
                                                <span className="chip chip-match">
                                                    DNA · {profile.dna_reference_type.replace(/_/g, " ")}
                                                </span>
                                            )}
                                            {!profile.dental_records_available &&
                                                !profile.prints_on_file &&
                                                !profile.dna_reference_type && (
                                                    <span className="chip chip-conflict">none</span>
                                                )}
                                        </div>
                                    </td>
                                    <td className="tbl-actions">
                                        <Link
                                            className="btn btn-sm"
                                            to={`/incidents/${incident.incident_id}/profiles/${profile.am_id}`}
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            View / Edit
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}
