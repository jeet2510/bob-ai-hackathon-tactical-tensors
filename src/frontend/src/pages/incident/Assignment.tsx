import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import api from "../../api/client";
import { toApiError } from "../../api/errors";
import { useIncident } from "../IncidentLayout";
import { BAND_CLASS, BAND_LABEL, signed } from "../../lib/format";
import type { AssignmentRow } from "../../types";

/**
 * Where scoring each body on its own disagrees with solving the incident as a
 * whole.
 *
 * A person can be in one place. When two near-identical people both rank first
 * against the same family report, at least one of those answers is wrong, and
 * a one-to-one assignment across the whole incident resolves it by preferring
 * a slightly weaker pairing that frees a much stronger one elsewhere. Those
 * disagreements are exactly the look-alike clusters, so they are listed first.
 */
export default function Assignment() {
    const { incident } = useIncident();
    const navigate = useNavigate();

    const [rows, setRows] = useState<AssignmentRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [onlyDiffering, setOnlyDiffering] = useState(false);

    useEffect(() => {
        api.get(`/incidents/${incident.incident_id}/assignment`)
            .then(({ data }) => setRows(data.rows))
            .catch((caught) => setError(toApiError(caught, "Could not load the assignment.").message))
            .finally(() => setLoading(false));
    }, [incident.incident_id]);

    if (loading) return <div className="empty">Loading…</div>;

    const differing = rows.filter((r) => r.differs);
    const shown = onlyDiffering ? differing : rows;

    return (
        <>
            <div className="page-head">
                <div>
                    <h2>Incident-wide assignment</h2>
                    <p className="muted">
                        The set of pairings that maximises total evidence across the incident under
                        a one-to-one constraint, beside the per-body ranking.
                    </p>
                </div>
            </div>

            {error && <div className="error-banner">{error}</div>}

            <div className="notice">
                <strong>{differing.length} of {rows.length} bodies</strong> are assigned differently
                from their independent first choice. Those rows are where two records competed for
                the same family report — the assignment is advisory and never overrides a reviewer.
            </div>

            <div className="toolbar">
                <label className="inline-check">
                    <input
                        type="checkbox"
                        checked={onlyDiffering}
                        onChange={(e) => setOnlyDiffering(e.target.checked)}
                    />
                    Show only disagreements
                </label>
            </div>

            <div className="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Body</th>
                            <th>Ranked first on its own</th>
                            <th>Preferred incident-wide</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((row) => (
                            <tr
                                key={row.pm_id}
                                className={`row-clickable${row.differs ? " row-flagged" : ""}`}
                                onClick={() => navigate(`/incidents/${incident.incident_id}/bodies/${row.pm_id}`)}
                            >
                                <td>
                                    <Link
                                        className="mono"
                                        to={`/incidents/${incident.incident_id}/bodies/${row.pm_id}`}
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        {row.pm_id}
                                    </Link>
                                </td>
                                <td>
                                    {row.ranked_first ? (
                                        <>
                                            {row.ranked_first.reported_name ?? row.ranked_first.am_id}
                                            <div className="faint">
                                                <Link
                                                    className="mono"
                                                    to={`/incidents/${incident.incident_id}/profiles/${row.ranked_first.am_id}`}
                                                    onClick={(e) => e.stopPropagation()}
                                                >
                                                    {row.ranked_first.am_id}
                                                </Link>
                                                {" "}· {signed(row.ranked_first.score)}
                                            </div>
                                        </>
                                    ) : (
                                        <span className="faint">none</span>
                                    )}
                                </td>
                                <td>
                                    {row.assigned ? (
                                        <>
                                            {row.assigned.reported_name ?? row.assigned.am_id}
                                            <div className="faint">
                                                <Link
                                                    className="mono"
                                                    to={`/incidents/${incident.incident_id}/profiles/${row.assigned.am_id}`}
                                                    onClick={(e) => e.stopPropagation()}
                                                >
                                                    {row.assigned.am_id}
                                                </Link>
                                                {" "}· {signed(row.assigned.score)}
                                            </div>
                                            <span
                                                className={`badge badge-${BAND_CLASS[row.assigned.confidence_band]}`}
                                            >
                                                {BAND_LABEL[row.assigned.confidence_band]}
                                            </span>
                                        </>
                                    ) : (
                                        <span className="faint">unassigned</span>
                                    )}
                                </td>
                                <td className="tbl-actions">
                                    <Link
                                        className="btn btn-sm"
                                        to={`/incidents/${incident.incident_id}/bodies/${row.pm_id}`}
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
        </>
    );
}
