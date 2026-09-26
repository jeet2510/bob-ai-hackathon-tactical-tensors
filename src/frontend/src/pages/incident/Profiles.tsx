import { useEffect, useState } from "react";
import api from "../../api/client";
import { toApiError } from "../../api/errors";
import { useIncident } from "../IncidentLayout";
import type { AmFile } from "../../types";

export default function Profiles() {
    const { incident } = useIncident();

    const [profiles, setProfiles] = useState<AmFile[]>([]);
    const [search, setSearch] = useState("");
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

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
            </div>

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
                            </tr>
                        </thead>
                        <tbody>
                            {profiles.map((profile) => (
                                <tr key={profile.am_id}>
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
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}
