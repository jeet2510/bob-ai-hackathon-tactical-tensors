import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { NavLink, Outlet, useParams } from "react-router-dom";
import api from "../api/client";
import { toApiError } from "../api/errors";
import Layout from "../components/Layout";
import type { Incident, IncidentStats, MatchRun } from "../types";
import { LANGUAGE } from "../lib/format";

interface IncidentContextValue {
    incident: Incident;
    stats: IncidentStats;
    run: MatchRun | null;
    languages: Record<string, number>;
    refresh: () => Promise<void>;
}

const IncidentContext = createContext<IncidentContextValue | null>(null);

export function useIncident(): IncidentContextValue {
    const value = useContext(IncidentContext);

    if (!value) {
        throw new Error("useIncident must be used inside the incident layout.");
    }

    return value;
}

export default function IncidentLayout() {
    const { incidentId } = useParams();

    const [state, setState] = useState<Omit<IncidentContextValue, "refresh"> | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    const refresh = useCallback(async () => {
        try {
            const { data } = await api.get(`/incidents/${incidentId}`);

            setState({
                incident: data.incident,
                stats: data.stats,
                run: data.run,
                languages: data.languages ?? {},
            });
            setError("");
        } catch (caught) {
            setError(toApiError(caught, "Could not load this incident.").message);
        } finally {
            setLoading(false);
        }
    }, [incidentId]);

    useEffect(() => {
        setLoading(true);
        refresh();
    }, [refresh]);

    if (loading) {
        return (
            <Layout>
                <div className="empty">Loading incident…</div>
            </Layout>
        );
    }

    if (error || !state) {
        return (
            <Layout>
                <div className="error-banner">{error || "Incident not found."}</div>
            </Layout>
        );
    }

    const { incident, run, languages } = state;
    const base = `/incidents/${incident.incident_id}`;

    const languageSummary = Object.entries(languages)
        .map(([code, count]) => `${LANGUAGE[code] ?? code} ${count}`)
        .join(" · ");

    return (
        <Layout>
            <div className="breadcrumb">
                <NavLink to="/incidents">Incidents</NavLink> / {incident.incident_id}
            </div>

            <div className="page-head">
                <div>
                    <h1>{incident.name}</h1>
                    <p>
                        {incident.incident_date}
                        {incident.district && ` · ${incident.district}`}
                        {run && ` · scored by ${run.scorer_version} on ${run.extractor_version} items`}
                    </p>
                    {languageSummary && (
                        <p className="faint">Form boxes by language — {languageSummary}</p>
                    )}
                </div>
            </div>

            {incident.synthetic && (
                <div className="notice">
                    <strong>Synthetic dataset.</strong> Every person, photograph and record in this
                    incident is fabricated for development. No entry refers to a real casualty.
                </div>
            )}

            <nav className="tabs">
                <NavLink to={base} end>
                    Command board
                </NavLink>
                <NavLink to={`${base}/profiles`}>Family reports</NavLink>
                <NavLink to={`${base}/assignment`}>Assignment</NavLink>
                <NavLink to={`${base}/report`}>Reconciliation</NavLink>
                <NavLink to={`${base}/evaluation`}>Evaluation lab</NavLink>
                <NavLink to={`${base}/audit`}>Audit trail</NavLink>
            </nav>

            <IncidentContext.Provider value={{ ...state, refresh }}>
                <Outlet />
            </IncidentContext.Provider>
        </Layout>
    );
}
