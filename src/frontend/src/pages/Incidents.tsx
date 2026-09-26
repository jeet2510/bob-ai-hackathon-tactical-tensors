import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../api/client";
import { toApiError } from "../api/errors";
import Layout from "../components/Layout";
import type { Incident } from "../types";

export default function Incidents() {
    const [incidents, setIncidents] = useState<Incident[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        api.get("/incidents")
            .then(({ data }) => setIncidents(data.incidents))
            .catch((caught) => setError(toApiError(caught, "Could not load incidents.").message))
            .finally(() => setLoading(false));
    }, []);

    return (
        <Layout>
            <div className="page-head">
                <div>
                    <h1>Incidents</h1>
                    <p>
                        Each incident holds its own post-mortem records, family reports and match
                        candidates. Records never cross between incidents.
                    </p>
                </div>
            </div>

            {error && <div className="error-banner">{error}</div>}

            {loading ? (
                <div className="empty">Loading…</div>
            ) : incidents.length === 0 ? (
                <div className="empty">
                    No incidents loaded. Run <span className="mono">php artisan dvi:ingest</span> to
                    load the dataset.
                </div>
            ) : (
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Incident</th>
                                <th>Date</th>
                                <th>District</th>
                                <th>Bodies</th>
                                <th>Family reports</th>
                            </tr>
                        </thead>
                        <tbody>
                            {incidents.map((incident) => (
                                <tr key={incident.incident_id}>
                                    <td className="mono">{incident.incident_id}</td>
                                    <td>
                                        <Link to={`/incidents/${incident.incident_id}`}>
                                            {incident.name}
                                        </Link>
                                        {incident.synthetic && (
                                            <>
                                                {" "}
                                                <span className="badge badge-accent">Synthetic</span>
                                            </>
                                        )}
                                    </td>
                                    <td>{incident.incident_date}</td>
                                    <td>{incident.district ?? "—"}</td>
                                    <td>{incident.pm_cases_count ?? 0}</td>
                                    <td>{incident.am_files_count ?? 0}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Layout>
    );
}
