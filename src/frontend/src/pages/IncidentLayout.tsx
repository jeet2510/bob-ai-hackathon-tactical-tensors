import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { NavLink, Outlet, useParams } from "react-router-dom";
import api from "../api/client";
import { toApiError } from "../api/errors";
import Layout from "../components/Layout";
import { IconChart, IconClipboard, IconFile, IconGrid, IconTarget, IconUsers } from "../components/icons";
import type { Incident, IncidentBreakdowns, IncidentStats, MatchRun } from "../types";
import { LANGUAGE } from "../lib/format";

interface IncidentContextValue {
    incident: Incident;
    stats: IncidentStats;
    run: MatchRun | null;
    languages: Record<string, number>;
    breakdowns: IncidentBreakdowns;
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

const EMPTY_BREAKDOWNS: IncidentBreakdowns = {
    body_condition: {},
    sex: {},
    dna_status: {},
    dental_status: {},
    print_status: {},
    recovered_by_day: {},
    photos_by_modality: {},
    photos_matchable: 0,
    photos_restricted: 0,
};

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
                breakdowns: data.breakdowns ?? EMPTY_BREAKDOWNS,
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

    const { incident, stats, run, languages } = state;
    const base = `/incidents/${incident.incident_id}`;

    const languageSummary = Object.entries(languages)
        .map(([code, count]) => `${LANGUAGE[code] ?? code} ${count}`)
        .join(" · ");

    const sidebar = (
        <>
            <div className="sidebar-incident">
                <div className="faint">
                    <NavLink to="/incidents">Incidents</NavLink>
                </div>
                <div className="sidebar-incident-name">{incident.name}</div>
                <div className="mono faint">{incident.incident_id}</div>
            </div>

            <nav className="sidebar-nav">
                <NavLink to={base} end className="sidebar-nav-link">
                    <IconGrid /> Dashboard
                </NavLink>
                <NavLink to={`${base}/bodies/new`} className="sidebar-nav-link">
                    <IconClipboard /> + Log a recovered body
                </NavLink>
                <NavLink to={`${base}/profiles`} className="sidebar-nav-link">
                    <IconUsers /> Family reports
                </NavLink>
                <NavLink to={`${base}/assignment`} className="sidebar-nav-link">
                    <IconTarget /> Assignment
                </NavLink>
                <NavLink to={`${base}/report`} className="sidebar-nav-link">
                    <IconFile /> Reconciliation
                </NavLink>
                <NavLink to={`${base}/evaluation`} className="sidebar-nav-link">
                    <IconChart /> Evaluation lab
                </NavLink>
                <NavLink to={`${base}/audit`} className="sidebar-nav-link">
                    <IconClipboard /> Audit trail
                </NavLink>
            </nav>

            <div className="sidebar-stats">
                <div className="sidebar-stat">
                    <span>Bodies</span>
                    <strong>{stats.bodies}</strong>
                </div>
                <div className="sidebar-stat">
                    <span>Family reports</span>
                    <strong>{stats.profiles}</strong>
                </div>
                {stats.matched && (
                    <div className="sidebar-stat">
                        <span>Awaiting review</span>
                        <strong>{stats.awaiting_review ?? 0}</strong>
                    </div>
                )}
            </div>
        </>
    );

    return (
        <Layout sidebar={sidebar}>
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

            <IncidentContext.Provider value={{ ...state, refresh }}>
                <Outlet />
            </IncidentContext.Provider>
        </Layout>
    );
}
