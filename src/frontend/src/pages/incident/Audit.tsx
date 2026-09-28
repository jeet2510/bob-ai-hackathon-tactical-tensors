import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../../api/client";
import { toApiError } from "../../api/errors";
import { useIncident } from "../IncidentLayout";
import { DECISION_LABEL } from "../../lib/format";
import type { ReviewDecisionRow } from "../../types";

/**
 * Every conclusion anyone has recorded, newest first.
 *
 * The log is append-only: a reversed decision appears as a later row, not as
 * an edit, so the history of what was believed and when survives intact. In a
 * process that ends with remains being released to a family, that history is
 * itself evidence.
 */
export default function Audit() {
    const { incident } = useIncident();

    const [decisions, setDecisions] = useState<ReviewDecisionRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        api.get(`/incidents/${incident.incident_id}/audit`)
            .then(({ data }) => setDecisions(data.decisions))
            .catch((caught) => setError(toApiError(caught, "Could not load the audit trail.").message))
            .finally(() => setLoading(false));
    }, [incident.incident_id]);

    if (loading) return <div className="empty">Loading…</div>;

    return (
        <>
            <div className="page-head">
                <div>
                    <h2>Audit trail</h2>
                    <p className="muted">
                        Append-only. Superseded decisions remain on the record beneath the ones
                        that replaced them.
                    </p>
                </div>
            </div>

            {error && <div className="error-banner">{error}</div>}

            {decisions.length === 0 ? (
                <div className="empty">No decisions recorded yet.</div>
            ) : (
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Body</th>
                                <th>Decision</th>
                                <th>Pairing</th>
                                <th>Reviewer</th>
                                <th>Note</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {decisions.map((d) => (
                                <tr key={d.id}>
                                    <td>{d.decided_at.slice(0, 16)}</td>
                                    <td>
                                        <Link
                                            className="mono"
                                            to={`/incidents/${incident.incident_id}/bodies/${d.pm_id}`}
                                        >
                                            {d.pm_id}
                                        </Link>
                                    </td>
                                    <td>
                                        <span className="badge badge-accent">
                                            {DECISION_LABEL[d.decision]}
                                        </span>
                                    </td>
                                    <td>
                                        {d.am_id ? (
                                            <Link
                                                className="mono"
                                                to={`/incidents/${incident.incident_id}/profiles/${d.am_id}`}
                                            >
                                                {d.am_id}
                                            </Link>
                                        ) : (
                                            <span className="faint">—</span>
                                        )}
                                    </td>
                                    <td>{d.reviewer}</td>
                                    <td className="muted">{d.note ?? "—"}</td>
                                    <td className="tbl-actions">
                                        <div className="btn-row">
                                            <Link
                                                className="btn btn-sm"
                                                to={`/incidents/${incident.incident_id}/bodies/${d.pm_id}`}
                                            >
                                                View body
                                            </Link>
                                            {d.am_id && (
                                                <Link
                                                    className="btn btn-sm"
                                                    to={`/incidents/${incident.incident_id}/profiles/${d.am_id}`}
                                                >
                                                    View profile
                                                </Link>
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
